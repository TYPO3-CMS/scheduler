<?php

declare(strict_types=1);

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

namespace TYPO3\CMS\Scheduler\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController as BackendController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Domain\DateTimeFormat;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Scheduler\CronCommand\NormalizeCommand;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\CMS\Scheduler\Exception\InvalidDateException;
use TYPO3\CMS\Scheduler\Exception\InvalidTaskException;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Scheduler;
use TYPO3\CMS\Scheduler\SchedulerManagementAction;
use TYPO3\CMS\Scheduler\Service\TaskService;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;
use TYPO3\CMS\Scheduler\Task\TaskSerializer;
use TYPO3\CMS\Scheduler\Validation\Validator\TaskValidator;

/**
 * Scheduler backend module.
 *
 * @internal This class is a specific Backend controller implementation and is not considered part of the Public TYPO3 API.
 */
#[BackendController]
final class SchedulerModuleController
{
    protected SchedulerManagementAction $currentAction;

    public function __construct(
        protected readonly Scheduler $scheduler,
        protected readonly TaskSerializer $taskSerializer,
        protected readonly SchedulerTaskRepository $taskRepository,
        protected readonly IconFactory $iconFactory,
        protected readonly UriBuilder $uriBuilder,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly Context $context,
        protected readonly TaskService $taskService,
    ) {}

    /**
     * Entry dispatcher method.
     *
     * There are two arguments involved regarding main module routing:
     * * 'action': add, edit, delete, toggleHidden, ...
     * * 'CMD': "save", "close", "new" when adding / editing a task.
     *          A better naming would be "nextAction", but the split button ModuleTemplate and
     *          DocumentSaveActions.ts can not cope with a renaming here and need "CMD".
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $view = $this->moduleTemplateFactory->create($request);
        $view->assign('dateFormat', [
            'day' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd-m-y',
            'time' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i',
        ]);

        $moduleData = $request->getAttribute('moduleData');

        // Simple actions from list view.
        if (!empty($parsedBody['action']['toggleHidden'])) {
            $this->toggleDisabledFlag($view, (int)$parsedBody['action']['toggleHidden']);
            return $this->renderListTasksView($view, $moduleData, $request);
        }
        if (!empty($parsedBody['action']['stop'])) {
            $this->stopTask($view, (int)$parsedBody['action']['stop']);
            return $this->renderListTasksView($view, $moduleData, $request);
        }
        if (!empty($parsedBody['execute'])) {
            $this->executeTasks($view, (string)$parsedBody['execute']);
            return $this->renderListTasksView($view, $moduleData, $request);
        }
        if (!empty($parsedBody['scheduleCron'])) {
            $this->scheduleCrons($view, (string)$parsedBody['scheduleCron']);
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        if (!empty($parsedBody['action']['group']['uid'])) {
            $this->groupDisable((int)$parsedBody['action']['group']['uid'], (int)($parsedBody['action']['group']['hidden'] ?? 0));
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        if (!empty($parsedBody['action']['delete'])) {
            $this->deleteTask($view, (int)$parsedBody['action']['delete']);
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        if (!empty($parsedBody['action']['groupRemove'])) {
            $rows = $this->groupRemove((int)$parsedBody['action']['groupRemove']);
            if ($rows > 0) {
                $view->addFlashMessage($this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.group.deleted'));
            } else {
                $view->addFlashMessage($this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.group.delete.failed'), '', ContextualFeedbackSeverity::WARNING);
            }

            return $this->renderListTasksView($view, $moduleData, $request);
        }

        $parsedAction = SchedulerManagementAction::tryFrom($parsedBody['action'] ?? '') ?? SchedulerManagementAction::LIST;

        if ($parsedAction === SchedulerManagementAction::ADD
            && in_array($parsedBody['CMD'] ?? '', ['save', 'saveclose', 'close'], true)
        ) {
            // Received data for adding a new task - validate, persist, render requested 'next' action.
            $isTaskDataValid = $this->isSubmittedTaskDataValid($view, $request->getParsedBody()['tx_scheduler'] ?? [], true);
            if (!$isTaskDataValid) {
                return $this->renderAddTaskFormView($view, $request);
            }
            $newTaskUid = $this->createTask($view, $request);
            if ($parsedBody['CMD'] === 'close') {
                return $this->renderListTasksView($view, $moduleData, $request);
            }
            if ($parsedBody['CMD'] === 'saveclose') {
                return $this->renderListTasksView($view, $moduleData, $request);
            }
            if ($parsedBody['CMD'] === 'save') {
                return $this->renderEditTaskFormView($view, $request, $newTaskUid);
            }
        }

        if ($parsedAction === SchedulerManagementAction::EDIT
            && in_array($parsedBody['CMD'] ?? '', ['save', 'close', 'saveclose', 'new'], true)
        ) {
            // Received data for updating existing task - validate, persist, render requested 'next' action.
            $isTaskDataValid = $this->isSubmittedTaskDataValid($view, $request->getParsedBody()['tx_scheduler'] ?? [], false);
            if (!$isTaskDataValid) {
                return $this->renderEditTaskFormView($view, $request);
            }
            $this->updateTask($view, $request);
            if ($parsedBody['CMD'] === 'new') {
                return $this->renderAddTaskFormView($view, $request);
            }
            if ($parsedBody['CMD'] === 'close') {
                return $this->renderListTasksView($view, $moduleData, $request);
            }
            if ($parsedBody['CMD'] === 'saveclose') {
                return $this->renderListTasksView($view, $moduleData, $request);
            }
            if ($parsedBody['CMD'] === 'save') {
                return $this->renderEditTaskFormView($view, $request);
            }
        }

        $queryAction = SchedulerManagementAction::tryFrom($queryParams['action'] ?? '') ?? SchedulerManagementAction::LIST;
        // Add new task form / edit existing task form.
        if ($queryAction === SchedulerManagementAction::ADD) {
            return $this->renderAddTaskFormView($view, $request);
        }
        if ($queryAction === SchedulerManagementAction::EDIT) {
            return $this->renderEditTaskFormView($view, $request);
        }

        // Render list if no other action kicked in.
        return $this->renderListTasksView($view, $moduleData, $request);
    }

    /**
     * This is (unfortunately) used by additional field providers to distinct between "create new task" and "edit task".
     */
    public function getCurrentAction(): SchedulerManagementAction
    {
        return $this->currentAction;
    }

    /**
     * This is (unfortunately) needed so getCurrentAction() used by additional field providers - it is required
     * to distinct between "create new task" and "edit task".
     */
    public function setCurrentAction(SchedulerManagementAction $currentAction): void
    {
        $this->currentAction = $currentAction;
    }

    /**
     * Mark a task as deleted.
     */
    protected function deleteTask(ModuleTemplate $view, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        if ($taskUid <= 0) {
            throw new \RuntimeException('Expecting a valid task uid', 1641670374);
        }
        try {
            // Try to fetch the task and delete it
            $task = $this->taskRepository->findByUid($taskUid);
            if ($this->taskRepository->isTaskMarkedAsRunning($task)) {
                // If the task is currently running, it may not be deleted
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.canNotDeleteRunningTask'), ContextualFeedbackSeverity::ERROR);
            } else {
                if ($this->taskRepository->remove($task)) {
                    $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteSuccess'));
                } else {
                    $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteError'));
                }
            }
        } catch (\UnexpectedValueException $e) {
            // The task could not be unserialized, simply update the database record setting it to deleted
            $result = $this->taskRepository->remove($taskUid);
            if ($result) {
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteSuccess'));
            } else {
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteError'), ContextualFeedbackSeverity::ERROR);
            }
        } catch (\OutOfBoundsException $e) {
            // The task was not found, for some reason
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), ContextualFeedbackSeverity::ERROR);
        }
    }

    /**
     * Clears the registered running executions from the task.
     * Note this doesn't actually stop the running script. It just unmark execution.
     * @todo find a way to really kill the running task.
     */
    protected function stopTask(ModuleTemplate $view, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        if (!$taskUid > 0) {
            throw new \RuntimeException('Expecting a valid task uid', 1641670375);
        }
        try {
            // Try to fetch the task and stop it
            $task = $this->taskRepository->findByUid($taskUid);
            if ($this->taskRepository->isTaskMarkedAsRunning($task)) {
                // If the task is indeed currently running, clear marked executions
                $result = $this->taskRepository->removeAllRegisteredExecutionsForTask($task);
                if ($result) {
                    $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopSuccess'));
                } else {
                    $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopError'), ContextualFeedbackSeverity::ERROR);
                }
            } else {
                // The task is not running, nothing to unmark
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.maynotStopNonRunningTask'), ContextualFeedbackSeverity::WARNING);
            }
        } catch (\OutOfBoundsException $e) {
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), ContextualFeedbackSeverity::ERROR);
        } catch (\UnexpectedValueException $e) {
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopTaskFailed'), $taskUid, $e->getMessage()), ContextualFeedbackSeverity::ERROR);
        }
    }

    /**
     * Toggle the disabled state of a task and register for next execution if task is of type "single execution".
     */
    protected function toggleDisabledFlag(ModuleTemplate $view, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        if (!$taskUid > 0) {
            throw new \RuntimeException('Expecting a valid task uid to toggle disabled state', 1641670373);
        }
        try {
            $task = $this->taskRepository->findByUid($taskUid);
            // Toggle the task state and add a flash message
            $taskName = $this->taskService->getHumanReadableTaskName($task);
            $isTaskDisabled = $task->isDisabled();
            // If a disabled single task is enabled again, register it for a single execution at next scheduler run.
            if ($isTaskDisabled && $task->getExecution()->isSingleRun()) {
                $task->setDisabled(false);
                $task->setRunOnNextCronJob(true);
                $execution = Execution::createSingleExecution($this->context->getAspect('date')->get('timestamp'));
                $task->setExecution($execution);
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabledAndQueuedForExecution'), $taskName, $taskUid));
            } elseif ($isTaskDisabled) {
                $task->setDisabled(false);
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabled'), $taskName, $taskUid));
            } else {
                $task->setDisabled(true);
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskDisabled'), $taskName, $taskUid));
            }
            $this->taskRepository->updateExecution($task);
        } catch (\OutOfBoundsException $e) {
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), ContextualFeedbackSeverity::ERROR);
        } catch (\UnexpectedValueException $e) {
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.toggleDisableFailed'), $taskUid, $e->getMessage()), ContextualFeedbackSeverity::ERROR);
        }
    }

    /**
     * Render add task form.
     */
    protected function renderAddTaskFormView(ModuleTemplate $view, ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $availableTaskTypes = $this->taskService->getAllTaskTypes();
        // Class selection can be GET - link and + button in info screen.
        $queryParams = $request->getQueryParams()['tx_scheduler'] ?? [];
        $parsedBody = $request->getParsedBody()['tx_scheduler'] ?? [];

        if ((int)($parsedBody['select_latest_group'] ?? 0) === 1) {
            $groups = array_column($this->getRegisteredTaskGroups(), 'uid');
            rsort($groups);
            $selectedTaskGroup = $groups[0] ?? 0;
        } else {
            $selectedTaskGroup = 0;
        }

        $currentData = [
            'taskType' => $parsedBody['taskType'] ?? $queryParams['taskType'] ?? key($availableTaskTypes),
            'disable' => (bool)($parsedBody['disable'] ?? false),
            'task_group' => $selectedTaskGroup,
            'runningType' => (int)($parsedBody['runningType'] ?? AbstractTask::TYPE_RECURRING),
            'start' => $parsedBody['start'] ?? $this->formatTimestampForDatePicker($this->context->getAspect('date')->get('timestamp')),
            'end' => $parsedBody['end'] ?? '',
            'frequency' => $parsedBody['frequency'] ?? '',
            'multiple' => (bool)($parsedBody['multiple'] ?? false),
            'description' => $parsedBody['description'] ?? '',
        ];

        // Group available tasks by extension name
        $categorizedTasks = $this->taskService->getCategorizedTaskTypes();

        // Additional field provider access $this->getCurrentAction() - Init it for them
        $this->currentAction = SchedulerManagementAction::ADD;
        // Get the extra fields to display for each task that needs some.
        $additionalFields = [];
        foreach ($availableTaskTypes as $taskType => $registrationInfo) {
            $providerObject = $this->taskService->getAdditionalFieldProviderForTask($taskType);
            if ($providerObject === null) {
                continue;
            }
            // Additional field providers receive form data by reference. But they shouldn't pollute our array here.
            $parseBodyForProvider = $request->getParsedBody()['tx_scheduler'] ?? [];
            // Hand over the correct command so we can populate the additional fields right away
            // In this case, the ExecuteSchedulableCommandTaskAdditionalFieldProvider is executed
            // multiple times (for each CLI command once)
            if ($registrationInfo['class'] === ExecuteSchedulableCommandTask::class) {
                $parseBodyForProvider['taskType'] = $taskType;
            }
            $fields = $providerObject->getAdditionalFields($parseBodyForProvider, null, $this);
            $additionalFields = $this->taskService->prepareAdditionalFields($taskType, $fields, $additionalFields);
        }

        $view->assignMultiple([
            'currentData' => $currentData,
            'categorizedTasks' => $categorizedTasks,
            'registeredTaskGroups' => $this->getRegisteredTaskGroups(),
            'preSelectedTaskGroup' => (int)($request->getQueryParams()['groupId'] ?? 0),
            'frequencyOptions' => (array)($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['frequencyOptions'] ?? []),
            'additionalFields' => $additionalFields,
            // Adding a group in edit view switches to formEngine. returnUrl is needed to go back to edit view on group record close.
            'returnUrl' => $request->getAttribute('normalizedParams')->getRequestUri(),
        ]);
        $view->makeDocHeaderModuleMenu();
        $this->addDocHeaderCloseAndSaveButtons($view);
        $view->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add')
        );
        $this->addDocHeaderShortcutButton($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add'), 'add');
        return $view->renderResponse('AddTaskForm');
    }

    /**
     * Render edit task form.
     */
    protected function renderEditTaskFormView(ModuleTemplate $view, ServerRequestInterface $request, ?int $taskUid = null): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $allTaskTypes = $this->taskService->getAllTaskTypes();
        $parsedBody = $request->getParsedBody()['tx_scheduler'] ?? [];
        $moduleData = $request->getAttribute('moduleData');
        $taskUid = (int)($taskUid ?? $request->getQueryParams()['uid'] ?? $parsedBody['uid'] ?? 0);
        if (empty($taskUid)) {
            throw new \RuntimeException('No valid task uid given to edit task', 1641720929);
        }
        $taskRecord = null;
        try {
            $taskRecord = $this->taskRepository->findRecordByUid($taskUid);
        } catch (\OutOfBoundsException) {
            // Todo: Why do we catch this global namespace exception is here, leaving all other exception unhandled?
        }
        if ($taskRecord === null) {
            // Task not found - removed meanwhile?
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), ContextualFeedbackSeverity::ERROR);
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        if (!empty($taskRecord['serialized_executions'])) {
            // If there's a registered execution, the task should not be edited. May happen if a cron started the task meanwhile.
            $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.maynotEditRunningTask'), ContextualFeedbackSeverity::ERROR);
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        $task = null;
        $taskType = null;
        $isInvalidTask = true;
        if (!empty($taskRecord['tasktype'])) {
            try {
                $task = $this->taskSerializer->deserialize($taskRecord);
                $taskType = $task->getTaskType();
                $isInvalidTask = false;
            } catch (InvalidTaskException) {
                $taskType = $taskRecord['tasktype'];
            }
        }

        if ($isInvalidTask || !isset($allTaskTypes[$taskType]) || !(new TaskValidator())->isValid($task)) {
            // The task object is not valid anymore. Add flash message and go back to list view.
            $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidTaskClassEdit'), $taskType), ContextualFeedbackSeverity::ERROR);
            return $this->renderListTasksView($view, $moduleData, $request);
        }

        $taskExecution = $task->getExecution();
        $taskName = $this->taskService->getHumanReadableTaskName($task);
        // If an interval or a cron command is defined, it's a recurring task
        $taskRunningType = (int)($parsedBody['runningType'] ?? ($taskExecution->isSingleRun() ? AbstractTask::TYPE_SINGLE : AbstractTask::TYPE_RECURRING));

        $currentData = [
            'taskType' => $taskType,
            'taskName' => $taskName,
            'disable' => (bool)($parsedBody['disable'] ?? $task->isDisabled()),
            'task_group' => (int)($parsedBody['task_group'] ?? $task->getTaskGroup()),
            'runningType' => $taskRunningType,
            'start' => $parsedBody['start'] ?? $this->formatTimestampForDatePicker($taskExecution->getStart()),
            // End for single execution tasks is always empty
            'end' => $parsedBody['end'] ?? ($taskRunningType === AbstractTask::TYPE_RECURRING ? $this->formatTimestampForDatePicker($taskExecution->getEnd()) : ''),
            // Find current frequency field value depending on task type and interval vs. cron command
            'frequency' => $parsedBody['frequency'] ?? ($taskRunningType === AbstractTask::TYPE_RECURRING ? ($taskExecution->getInterval() ?: $taskExecution->getCronCmd()) : ''),
            'multiple' => !($taskRunningType === AbstractTask::TYPE_SINGLE) && (bool)($parsedBody['multiple'] ?? $taskExecution->getMultiple()),
            'description' => $parsedBody['description'] ?? $task->getDescription(),
        ];

        // Additional field provider access $this->getCurrentAction() - Init it for them
        $this->currentAction = SchedulerManagementAction::EDIT;
        $additionalFields = [];
        $providerObject = $this->taskService->getAdditionalFieldProviderForTask($taskType);
        if ($providerObject !== null) {
            // Additional field providers receive form data by reference. But they shouldn't pollute our array here.
            $parseBodyForProvider = $request->getParsedBody()['tx_scheduler'] ?? [];
            $fields = $providerObject->getAdditionalFields($parseBodyForProvider, $task, $this);
            $additionalFields = $this->taskService->prepareAdditionalFields((string)$taskType, $fields);
        }

        $view->assignMultiple([
            'uid' => $taskUid,
            'action' => 'edit',
            'currentData' => $currentData,
            'registeredTaskGroups' => $this->getRegisteredTaskGroups(),
            'frequencyOptions' => (array)($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['frequencyOptions'] ?? []),
            'additionalFields' => $additionalFields,
            // Adding a group in edit view switches to formEngine. returnUrl is needed to go back to edit view on group record close.
            'returnUrl' => $request->getAttribute('normalizedParams')->getRequestUri(),
        ]);
        $view->makeDocHeaderModuleMenu();
        $this->addDocHeaderCloseAndSaveButtons($view);
        $view->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.edit'), $taskName)
        );
        $this->addDocHeaderDeleteButton($view, $taskUid);
        $this->addDocHeaderShortcutButton(
            $view,
            sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.edit'), $taskName),
            'edit',
            $taskUid
        );
        return $view->renderResponse('EditTaskForm');
    }

    /**
     * Execute a list of tasks.
     */
    protected function executeTasks(ModuleTemplate $view, string $taskUids): void
    {
        $taskUids = GeneralUtility::intExplode(',', $taskUids, true);
        if (empty($taskUids)) {
            throw new \RuntimeException('Expecting a list of task uids to execute', 1641715832);
        }
        // Loop selected tasks and execute.
        $languageService = $this->getLanguageService();
        foreach ($taskUids as $uid) {
            try {
                $task = $this->taskRepository->findByUid($uid);
                $name = $this->taskService->getHumanReadableTaskName($task);
                // Try to execute it and report result
                $result = $this->scheduler->executeTask($task);
                if ($result) {
                    $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.executed'), $name, $uid));
                } else {
                    $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.notExecuted'), $name, $uid), ContextualFeedbackSeverity::ERROR);
                }
                $this->scheduler->recordLastRun('manual');
            } catch (\OutOfBoundsException $e) {
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $uid), ContextualFeedbackSeverity::ERROR);
            } catch (\Exception $e) {
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.executionFailed'), $uid, $e->getMessage()), ContextualFeedbackSeverity::ERROR);
            }
        }
    }

    /**
     * Schedule selected tasks to be executed on next cron run
     */
    protected function scheduleCrons(ModuleTemplate $view, string $taskUids): void
    {
        $taskUids = GeneralUtility::intExplode(',', $taskUids, true);
        if (empty($taskUids)) {
            throw new \RuntimeException('Expecting a list of task uids to schedule', 1641715833);
        }
        // Loop selected tasks and register for next cron run.
        $languageService = $this->getLanguageService();
        foreach ($taskUids as $uid) {
            try {
                $task = $this->taskRepository->findByUid($uid);
                $name = $this->taskService->getHumanReadableTaskName($task);
                $task->setRunOnNextCronJob(true);
                if ($task->isDisabled()) {
                    $task->setDisabled(false);
                    $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabledAndQueuedForExecution'), $name, $uid));
                } else {
                    $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskQueuedForExecution'), $name, $uid));
                }
                $this->taskRepository->updateExecution($task);
            } catch (\OutOfBoundsException $e) {
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $uid), ContextualFeedbackSeverity::ERROR);
            } catch (\UnexpectedValueException $e) {
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.schedulingFailed'), $uid, $e->getMessage()), ContextualFeedbackSeverity::ERROR);
            }
        }
    }

    /**
     * Assemble a listing of scheduled tasks
     */
    protected function renderListTasksView(ModuleTemplate $view, ModuleData $moduleData, ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $data = $this->taskRepository->getGroupedTasks();
        $allTaskTypes = $this->taskService->getAllTaskTypes();

        $groups = $data['taskGroupsWithTasks'] ?? [];
        $groups = array_map(
            static fn(int $key, array $group): array => array_merge($group, ['taskGroupCollapsed' => (bool)($moduleData->get('task-group-' . $key, false))]),
            array_keys($groups),
            $groups
        );

        $view->assignMultiple([
            'groups' => $groups,
            'groupsWithoutTasks' => $this->getGroupsWithoutTasks($groups),
            'now' => $this->context->getAspect('date')->get('timestamp'),
            'errorClasses' => $data['errorClasses'],
            'errorClassesCollapsed' => (bool)($moduleData->get('task-group-missing', false)),
        ]);
        $view->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.scheduler')
        );
        $view->makeDocHeaderModuleMenu();
        $this->addDocHeaderReloadButton($view);
        if (!empty($allTaskTypes)) {
            $this->addDocHeaderAddTaskButton($view, $request);
            $this->addDocHeaderAddTaskGroupButton($view);
        }
        $this->addDocHeaderShortcutButton($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.scheduler'));
        return $view->renderResponse('ListTasks');
    }

    protected function isSubmittedTaskDataValid(ModuleTemplate $view, array $parsedBody, bool $isNewTask): bool
    {
        if ($parsedBody === []) {
            return false;
        }
        $languageService = $this->getLanguageService();
        $taskType = (string)($parsedBody['taskType'] ?? '');
        $runningType = (int)($parsedBody['runningType'] ?? 0);
        $startTime = $parsedBody['start'] ?? 0;
        $endTime = $parsedBody['end'] ?? 0;
        $result = true;
        if ($isNewTask) {
            // @todo: check how this could ever happen, that a task class is registered but not exists, this might be removed once we have a better registration API in place.
            $taskClass = $this->taskService->getAllTaskTypes()[$taskType]['class'] ?? '';
            if ($taskClass === '' || !class_exists($taskClass)) {
                $result = false;
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noTaskClassFound'), ContextualFeedbackSeverity::ERROR);
            }
        } else {
            try {
                $taskUid = (int)($parsedBody['uid'] ?? 0);
                $this->taskRepository->findByUid($taskUid);
            } catch (\OutOfBoundsException|\UnexpectedValueException $e) {
                $result = false;
                $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), ContextualFeedbackSeverity::ERROR);
            }
        }
        if ($runningType !== AbstractTask::TYPE_SINGLE && $runningType !== AbstractTask::TYPE_RECURRING) {
            $result = false;
            $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidTaskType'), ContextualFeedbackSeverity::ERROR);
        }
        if (empty($startTime)) {
            $result = false;
            $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noStartDate'), ContextualFeedbackSeverity::ERROR);
        } else {
            try {
                $startTime = $this->getTimestampFromDateString($startTime);
            } catch (InvalidDateException $e) {
                $result = false;
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidStartDate'), ContextualFeedbackSeverity::ERROR);
            }
        }
        if ($runningType === AbstractTask::TYPE_RECURRING && !empty($endTime)) {
            try {
                $endTime = $this->getTimestampFromDateString($endTime);
            } catch (InvalidDateException $e) {
                $result = false;
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidStartDate'), ContextualFeedbackSeverity::ERROR);
            }
        }
        if ($runningType === AbstractTask::TYPE_RECURRING && $endTime > 0 && $endTime < $startTime) {
            $result = false;
            $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.endDateSmallerThanStartDate'), ContextualFeedbackSeverity::ERROR);
        }
        if ($runningType === AbstractTask::TYPE_RECURRING) {
            if (empty(trim($parsedBody['frequency']))) {
                $result = false;
                $this->addMessage($view, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noFrequency'), ContextualFeedbackSeverity::ERROR);
            } elseif (!is_numeric(trim($parsedBody['frequency']))) {
                try {
                    NormalizeCommand::normalize(trim($parsedBody['frequency']));
                } catch (\InvalidArgumentException $e) {
                    $result = false;
                    $this->addMessage($view, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.frequencyError'), $e->getMessage(), $e->getCode()), ContextualFeedbackSeverity::ERROR);
                }
            }
        }
        $provider = $this->taskService->getAdditionalFieldProviderForTask($taskType);
        if ($provider !== null) {
            // Providers should add messages for failed validations on their own.
            $result = $result && $provider->validateAdditionalFields($parsedBody, $this);
        }
        return $result;
    }

    /**
     * Create a new task and persist. Return its new uid.
     */
    protected function createTask(ModuleTemplate $view, ServerRequestInterface $request): int
    {
        $taskType = $request->getParsedBody()['tx_scheduler']['taskType'];
        $task = $this->taskService->createNewTask($taskType);
        $task = $this->taskService->setTaskDataFromRequest($task, $request->getParsedBody()['tx_scheduler'] ?? []);
        if (!$this->taskRepository->add($task)) {
            throw new \RuntimeException('Unable to add task. Possible database error', 1641720169);
        }
        $this->addMessage($view, $this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.addSuccess'));
        return $task->getTaskUid();
    }

    /**
     * Update data of an existing task.
     */
    protected function updateTask(ModuleTemplate $view, ServerRequestInterface $request): void
    {
        $task = $this->taskRepository->findByUid((int)$request->getParsedBody()['tx_scheduler']['uid']);
        $task = $this->taskService->setTaskDataFromRequest($task, $request->getParsedBody()['tx_scheduler'] ?? []);
        $fields = $this->taskService->getFieldsForRecord($task);
        $this->taskRepository->update($task, $fields);
        $this->addMessage($view, $this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.updateSuccess'));
    }

    /**
     * Convert input to DateTime and retrieve timestamp.
     *
     * @throws InvalidDateException
     */
    protected function getTimestampFromDateString(string $input): int
    {
        if ($input === '') {
            return 0;
        }
        if (MathUtility::canBeInterpretedAsInteger($input)) {
            // Already looks like a timestamp
            return (int)$input;
        }
        try {
            // Convert from ISO 8601 dates
            $value = (new \DateTime($input))->getTimestamp();
        } catch (\Exception $e) {
            throw new InvalidDateException($e->getMessage(), 1641717510);
        }
        return $value;
    }

    protected function formatTimestampForDatePicker(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }
        return date(DateTimeFormat::ISO8601_LOCALTIME, $timestamp);
    }

    /**
     * Fetch list of all task groups.
     */
    protected function getRegisteredTaskGroups(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task_group');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);

        return $queryBuilder->select('*')
            ->from('tx_scheduler_task_group')
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function addDocHeaderReloadButton(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $reloadButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('scheduler_manage'));
        $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    protected function addDocHeaderAddTaskButton(ModuleTemplate $moduleTemplate, ServerRequestInterface $request): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $params = [
            'returnUrl' => $request->getAttribute('normalizedParams')->getRequestUri(),
            'edit' => [
                'tx_scheduler_task' => [
                    0 => 'new',
                ],
            ],
            'defVals' => [
                'tx_scheduler_task' => [
                    'pid' => 0,
                ],
            ],
        ];

        $addButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('record_edit', $params));
        $buttonBar->addButton($addButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    private function addDocHeaderAddTaskGroupButton(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $addButton = $buttonBar->makeInputButton()
            ->setTitle($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.group.add'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setName('createSchedulerGroup')
            ->setValue('1')
            ->setClasses('t3js-create-group');
        $buttonBar->addButton($addButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
    }

    protected function addDocHeaderCloseAndSaveButtons(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $closeButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:close'))
            ->setIcon($this->iconFactory->getIcon('actions-close', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('scheduler_manage'))
            ->setClasses('t3js-scheduler-close');
        $buttonBar->addButton($closeButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        $saveButton = $buttonBar->makeInputButton()
            ->setName('CMD')
            ->setValue('save')
            ->setForm('tx_scheduler_form')
            ->setIcon($this->iconFactory->getIcon('actions-document-save', IconSize::SMALL))
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:save'))
            ->setShowLabelText(true);
        $buttonBar->addButton($saveButton, ButtonBar::BUTTON_POSITION_LEFT, 4);
    }

    protected function addDocHeaderDeleteButton(ModuleTemplate $moduleTemplate, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $deleteButton = GeneralUtility::makeInstance(GenericButton::class)
            ->setTag('button')
            ->setClasses('btn btn-default t3js-modal-trigger')
            ->setAttributes([
                'type' => 'submit',
                'data-target-form' => 'tx_scheduler_form_delete_' . $taskUid,
                'data-severity' => 'warning',
                'data-title' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:delete'),
                'data-button-close-text' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:cancel'),
                'data-bs-content' => $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.delete'),
            ])
            ->setIcon($this->iconFactory->getIcon('actions-edit-delete', IconSize::SMALL))
            ->setLabel($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:delete'))
            ->setShowLabelText(true);
        $buttonBar->addButton($deleteButton, ButtonBar::BUTTON_POSITION_LEFT, 6);
    }

    protected function addDocHeaderShortcutButton(ModuleTemplate $moduleTemplate, string $name, string $action = '', int $taskUid = 0): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $shortcutArguments = [];
        if ($action) {
            $shortcutArguments['action'] = $action;
        }
        if ($taskUid) {
            $shortcutArguments['uid'] = $taskUid;
        }
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('scheduler_manage')
            ->setDisplayName($name)
            ->setArguments($shortcutArguments);
        $buttonBar->addButton($shortcutButton);
    }

    /**
     * Add a flash message to the flash message queue of this module.
     */
    protected function addMessage(ModuleTemplate $moduleTemplate, string $message, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): void
    {
        $moduleTemplate->addFlashMessage($message, '', $severity);
    }

    private function getGroupsWithoutTasks(array $taskGroupsWithTasks): array
    {
        $uidGroupsWithTasks = array_filter(array_column($taskGroupsWithTasks, 'uid'));
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task_group');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $resultEmptyGroups = $queryBuilder->select('*')
            ->from('tx_scheduler_task_group')
            ->orderBy('groupName');

        // Only add where statement if we have taskGroups to consider.
        if (!empty($uidGroupsWithTasks)) {
            $resultEmptyGroups->where($queryBuilder->expr()->notIn('uid', $uidGroupsWithTasks));
        }

        return $resultEmptyGroups->executeQuery()->fetchAllAssociative();
    }

    private function groupRemove(int $groupId): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task_group');
        return $queryBuilder->update('tx_scheduler_task_group')
            ->where($queryBuilder->expr()->eq('uid', $groupId))
            ->set('deleted', 1)
            ->executeStatement();
    }

    private function groupDisable(int $groupId, int $hidden): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task_group');
        $queryBuilder->update('tx_scheduler_task_group')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($groupId)))
            ->set('hidden', $hidden)
            ->executeStatement();
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
