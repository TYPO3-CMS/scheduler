<?php
namespace TYPO3\CMS\Scheduler;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * TYPO3 Scheduler. This class handles scheduling and execution of tasks.
 * Formerly known as "Gabriel TYPO3 arch angel"
 */
class Scheduler implements \TYPO3\CMS\Core\SingletonInterface
{
    /**
     * @var array $extConf Settings from the extension manager
     */
    public $extConf = [];

    /**
     * Constructor, makes sure all derived client classes are included
     *
     * @return \TYPO3\CMS\Scheduler\Scheduler
     */
    public function __construct()
    {
        // Get configuration from the extension manager
        $this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['scheduler'], ['allowed_classes' => false]);
        if (empty($this->extConf['maxLifetime'])) {
            $this->extConf['maxLifetime'] = 1440;
        }
        if (empty($this->extConf['useAtdaemon'])) {
            $this->extConf['useAtdaemon'] = 0;
        }
        // Clean up the serialized execution arrays
        $this->cleanExecutionArrays();
    }

    /**
     * Adds a task to the pool
     *
     * @param Task\AbstractTask $task The object representing the task to add
     * @return bool TRUE if the task was successfully added, FALSE otherwise
     */
    public function addTask(Task\AbstractTask $task)
    {
        $taskUid = $task->getTaskUid();
        if (empty($taskUid)) {
            $fields = [
                'crdate' => $GLOBALS['EXEC_TIME'],
                'disable' => (int)$task->isDisabled(),
                'description' => $task->getDescription(),
                'task_group' => $task->getTaskGroup(),
                'serialized_task_object' => 'RESERVED'
            ];
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_scheduler_task');
            $result = $connection->insert(
                'tx_scheduler_task',
                $fields,
                ['serialized_task_object' => Connection::PARAM_LOB]
            );

            if ($result) {
                $task->setTaskUid($connection->lastInsertId('tx_scheduler_task'));
                $task->save();
                $result = true;
            } else {
                $result = false;
            }
        } else {
            $result = false;
        }
        return $result;
    }

    /**
     * Cleans the execution lists of the scheduled tasks, executions older than 24h are removed
     * @todo find a way to actually kill the job
     */
    protected function cleanExecutionArrays()
    {
        $tstamp = $GLOBALS['EXEC_TIME'];
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_scheduler_task');

        // Select all tasks with executions
        // NOTE: this cleanup is done for disabled tasks too,
        // to avoid leaving old executions lying around
        $result = $queryBuilder->select('uid', 'serialized_executions', 'serialized_task_object')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->neq(
                    'serialized_executions',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                )
            )
            ->execute();
        $maxDuration = $this->extConf['maxLifetime'] * 60;
        while ($row = $result->fetch()) {
            $executions = [];
            if ($serialized_executions = unserialize($row['serialized_executions'])) {
                foreach ($serialized_executions as $task) {
                    if ($tstamp - $task < $maxDuration) {
                        $executions[] = $task;
                    } else {
                        $task = unserialize($row['serialized_task_object']);
                        $logMessage = 'Removing logged execution, assuming that the process is dead. Execution of \'' . get_class($task) . '\' (UID: ' . $row['uid'] . ') was started at ' . date('Y-m-d H:i:s', $task->getExecutionTime());
                        $this->log($logMessage);
                    }
                }
            }
            $executionCount = count($executions);
            if (count($serialized_executions) !== $executionCount) {
                if ($executionCount === 0) {
                    $value = '';
                } else {
                    $value = serialize($executions);
                }
                $connectionPool->getConnectionForTable('tx_scheduler_task')->update(
                    'tx_scheduler_task',
                    ['serialized_executions' => $value],
                    ['uid' => (int)$row['uid']],
                    ['serialized_executions' => Connection::PARAM_LOB]
                );
            }
        }
    }

    /**
     * This method executes the given task and properly marks and records that execution
     * It is expected to return FALSE if the task was barred from running or if it was not saved properly
     *
     * @param Task\AbstractTask $task The task to execute
     * @return bool Whether the task was saved successfully to the database or not
     * @throws FailedExecutionException
     * @throws \Exception
     */
    public function executeTask(Task\AbstractTask $task)
    {
        $task->setRunOnNextCronJob(false);
        // Trigger the saving of the task, as this will calculate its next execution time
        // This should be calculated all the time, even if the execution is skipped
        // (in case it is skipped, this pushes back execution to the next possible date)
        $task->save();
        // Set a scheduler object for the task again,
        // as it was removed during the save operation
        $task->setScheduler();
        $result = true;
        // Task is already running and multiple executions are not allowed
        if (!$task->areMultipleExecutionsAllowed() && $task->isExecutionRunning()) {
            // Log multiple execution error
            $logMessage = 'Task is already running and multiple executions are not allowed, skipping! Class: ' . get_class($task) . ', UID: ' . $task->getTaskUid();
            $this->log($logMessage);
            $result = false;
        } else {
            // Log scheduler invocation
            $logMessage = 'Start execution. Class: ' . get_class($task) . ', UID: ' . $task->getTaskUid();
            $this->log($logMessage);
            // Register execution
            $executionID = $task->markExecution();
            $failure = null;
            try {
                // Execute task
                $successfullyExecuted = $task->execute();
                if (!$successfullyExecuted) {
                    throw new FailedExecutionException('Task failed to execute successfully. Class: ' . get_class($task) . ', UID: ' . $task->getTaskUid(), 1250596541);
                }
            } catch (\Exception $e) {
                // Store exception, so that it can be saved to database
                $failure = $e;
            }
            // Un-register execution
            $task->unmarkExecution($executionID, $failure);
            // Log completion of execution
            $logMessage = 'Task executed. Class: ' . get_class($task) . ', UID: ' . $task->getTaskUid();
            $this->log($logMessage);
            // Now that the result of the task execution has been handled,
            // throw the exception again, if any
            if ($failure instanceof \Exception) {
                throw $failure;
            }
        }
        return $result;
    }

    /**
     * This method stores information about the last run of the Scheduler into the system registry
     *
     * @param string $type Type of run (manual or command-line (assumed to be cron))
     */
    public function recordLastRun($type = 'cron')
    {
        // Validate input value
        if ($type !== 'manual' && $type !== 'cli-by-id') {
            $type = 'cron';
        }
        /** @var Registry $registry */
        $registry = GeneralUtility::makeInstance(Registry::class);
        $runInformation = ['start' => $GLOBALS['EXEC_TIME'], 'end' => time(), 'type' => $type];
        $registry->set('tx_scheduler', 'lastRun', $runInformation);
    }

    /**
     * Removes a task completely from the system.
     *
     * @todo find a way to actually kill the existing jobs
     *
     * @param Task\AbstractTask $task The object representing the task to delete
     * @return bool TRUE if task was successfully deleted, FALSE otherwise
     */
    public function removeTask(Task\AbstractTask $task)
    {
        $taskUid = $task->getTaskUid();
        if (!empty($taskUid)) {
            $result = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_scheduler_task')
                ->delete('tx_scheduler_task', ['uid' => $taskUid]);
        } else {
            $result = false;
        }
        if ($result) {
            $this->scheduleNextSchedulerRunUsingAtDaemon();
        }
        return $result;
    }

    /**
     * Updates a task in the pool
     *
     * @param Task\AbstractTask $task Scheduler task object
     * @return bool False if submitted task was not of proper class
     */
    public function saveTask(Task\AbstractTask $task)
    {
        $taskUid = $task->getTaskUid();
        if (!empty($taskUid)) {
            try {
                if ($task->getRunOnNextCronJob()) {
                    $executionTime = time();
                } else {
                    $executionTime = $task->getNextDueExecution();
                }
                $task->setExecutionTime($executionTime);
            } catch (\Exception $e) {
                $task->setDisabled(true);
                $executionTime = 0;
            }
            $task->unsetScheduler();
            $fields = [
                'nextexecution' => $executionTime,
                'disable' => (int)$task->isDisabled(),
                'description' => $task->getDescription(),
                'task_group' => $task->getTaskGroup(),
                'serialized_task_object' => serialize($task)
            ];
            $result = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_scheduler_task')
                ->update(
                    'tx_scheduler_task',
                    $fields,
                    ['uid' => $taskUid],
                    ['serialized_task_object' => Connection::PARAM_LOB]
                );
        } else {
            $result = false;
        }
        if ($result) {
            $this->scheduleNextSchedulerRunUsingAtDaemon();
        }
        return $result;
    }

    /**
     * Fetches and unserializes a task object from the db. If an uid is given the object
     * with the uid is returned, else the object representing the next due task is returned.
     * If there are no due tasks the method throws an exception.
     *
     * @param int $uid Primary key of a task
     * @return Task\AbstractTask The fetched task object
     * @throws \OutOfBoundsException
     * @throws \UnexpectedValueException
     */
    public function fetchTask($uid = 0)
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_scheduler_task');

        $queryBuilder->select('t.uid', 't.serialized_task_object')
            ->from('tx_scheduler_task', 't')
            ->setMaxResults(1);
        // Define where clause
        // If no uid is given, take any non-disabled task which has a next execution time in the past
        if (empty($uid)) {
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->leftJoin(
                't',
                'tx_scheduler_task_group',
                'g',
                $queryBuilder->expr()->eq('t.task_group', $queryBuilder->quoteIdentifier('g.uid'))
            );
            $queryBuilder->where(
                $queryBuilder->expr()->eq('t.disable', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->neq('t.nextexecution', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->lte(
                    't.nextexecution',
                    $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'], \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq('g.hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->isNull('g.hidden')
                )
            );
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->eq('t.uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT))
            );
        }

        $row = $queryBuilder->execute()->fetch();
        if ($row === false) {
            throw new \OutOfBoundsException('Query could not be executed. Possible defect in tables tx_scheduler_task or tx_scheduler_task_group or DB server problems', 1422044826);
        }
        if (empty($row)) {
            // If there are no available tasks, thrown an exception
            throw new \OutOfBoundsException('No task', 1247827244);
        }
        /** @var $task Task\AbstractTask */
        $task = unserialize($row['serialized_task_object']);
        if ($this->isValidTaskObject($task)) {
            // The task is valid, return it
            $task->setScheduler();
        } else {
            // Forcibly set the disable flag to 1 in the database,
            // so that the task does not come up again and again for execution
            $connectionPool->getConnectionForTable('tx_scheduler_task')->update(
                    'tx_scheduler_task',
                    ['disable' => 1],
                    ['uid' => (int)$row['uid']]
                );
            // Throw an exception to raise the problem
            throw new \UnexpectedValueException('Could not unserialize task', 1255083671);
        }

        return $task;
    }

    /**
     * This method is used to get the database record for a given task
     * It returns the database record and not the task object
     *
     * @param int $uid Primary key of the task to get
     * @return array Database record for the task
     * @see \TYPO3\CMS\Scheduler\Scheduler::fetchTask()
     * @throws \OutOfBoundsException
     */
    public function fetchTaskRecord($uid)
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task')
            ->select(['*'], 'tx_scheduler_task', ['uid' => (int)$uid])
            ->fetch();

        // If the task is not found, throw an exception
        if (empty($row)) {
            throw new \OutOfBoundsException('No task', 1247827245);
        }

        return $row;
    }

    /**
     * Fetches and unserializes task objects selected with some (SQL) condition
     * Objects are returned as an array
     *
     * @param string $where Part of a SQL where clause (without the "WHERE" keyword)
     * @param bool $includeDisabledTasks TRUE if disabled tasks should be fetched too, FALSE otherwise
     * @return array List of task objects
     */
    public function fetchTasksWithCondition($where, $includeDisabledTasks = false)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_scheduler_task');

        $constraints = [];
        $tasks = [];

        if (!$includeDisabledTasks) {
            $constraints[] = $queryBuilder->expr()->eq(
                'disable',
                $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
            );
        } else {
            $constraints[] = '1=1';
        }

        if (!empty($where)) {
            $constraints[] = QueryHelper::stripLogicalOperatorPrefix($where);
        }

        $result = $queryBuilder->select('serialized_task_object')
            ->from('tx_scheduler_task')
            ->where(...$constraints)
            ->execute();

        while ($row = $result->fetch()) {
            /** @var Task\AbstractTask $task */
            $task = unserialize($row['serialized_task_object']);
            // Add the task to the list only if it is valid
            if ($this->isValidTaskObject($task)) {
                $task->setScheduler();
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * This method encapsulates a very simple test for the purpose of clarity.
     * Registered tasks are stored in the database along with a serialized task object.
     * When a registered task is fetched, its object is unserialized.
     * At that point, if the class corresponding to the object is not available anymore
     * (e.g. because the extension providing it has been uninstalled),
     * the unserialization will produce an incomplete object.
     * This test checks whether the unserialized object is of the right (parent) class or not.
     *
     * @param object $task The object to test
     * @return bool TRUE if object is a task, FALSE otherwise
     */
    public function isValidTaskObject($task)
    {
        return $task instanceof Task\AbstractTask;
    }

    /**
     * This is a utility method that writes some message to the BE Log
     * It could be expanded to write to some other log
     *
     * @param string $message The message to write to the log
     * @param int $status Status (0 = message, 1 = error)
     * @param mixed $code Key for the message
     */
    public function log($message, $status = 0, $code = 'scheduler')
    {
        // Log only if enabled
        if (!empty($this->extConf['enableBELog'])) {
            $GLOBALS['BE_USER']->writelog(4, 0, $status, 0, '[scheduler]: ' . $code . ' - ' . $message, []);
        }
    }

    /**
     * Schedule the next run of scheduler
     * For the moment only the "at"-daemon is used, and only if it is enabled
     *
     * @return bool Successfully scheduled next execution using "at"-daemon
     * @see tx_scheduler::fetchTask()
     */
    public function scheduleNextSchedulerRunUsingAtDaemon()
    {
        if ((int)$this->extConf['useAtdaemon'] !== 1) {
            return false;
        }
        /** @var $registry Registry */
        $registry = GeneralUtility::makeInstance(Registry::class);
        // Get at job id from registry and remove at job
        $atJobId = $registry->get('tx_scheduler', 'atJobId');
        if (MathUtility::canBeInterpretedAsInteger($atJobId)) {
            shell_exec('atrm ' . (int)$atJobId . ' 2>&1');
        }
        // Can not use fetchTask() here because if tasks have just executed
        // they are not in the list of next executions
        $tasks = $this->fetchTasksWithCondition('');
        $nextExecution = false;
        foreach ($tasks as $task) {
            try {
                /** @var $task Task\AbstractTask */
                $tempNextExecution = $task->getNextDueExecution();
                if ($nextExecution === false || $tempNextExecution < $nextExecution) {
                    $nextExecution = $tempNextExecution;
                }
            } catch (\OutOfBoundsException $e) {
                // The event will not be executed again or has already ended - we don't have to consider it for
                // scheduling the next "at" run
            }
        }
        if ($nextExecution !== false) {
            if ($nextExecution > $GLOBALS['EXEC_TIME']) {
                $startTime = strftime('%H:%M %F', $nextExecution);
            } else {
                $startTime = 'now+1minute';
            }
            $cliDispatchPath = PATH_site . 'typo3/sysext/core/bin/typo3';
            list($cliDispatchPathEscaped, $startTimeEscaped) =
                CommandUtility::escapeShellArguments([$cliDispatchPath, $startTime]);
            $cmd = 'echo ' . $cliDispatchPathEscaped . ' scheduler:run | at ' . $startTimeEscaped . ' 2>&1';
            $output = shell_exec($cmd);
            $outputParts = '';
            foreach (explode(LF, $output) as $outputLine) {
                if (GeneralUtility::isFirstPartOfStr($outputLine, 'job')) {
                    $outputParts = explode(' ', $outputLine, 3);
                    break;
                }
            }
            if ($outputParts[0] === 'job' && MathUtility::canBeInterpretedAsInteger($outputParts[1])) {
                $atJobId = (int)$outputParts[1];
                $registry->set('tx_scheduler', 'atJobId', $atJobId);
            }
        }
        return true;
    }
}
