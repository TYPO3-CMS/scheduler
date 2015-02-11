<?php
namespace TYPO3\CMS\Scheduler\Controller;

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

use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Saltedpasswords\Salt\SaltFactory;
use TYPO3\CMS\Saltedpasswords\Utility\SaltedPasswordsUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Module 'TYPO3 Scheduler administration module' for the 'scheduler' extension.
 *
 * @author François Suter <francois@typo3.org>
 * @author Christian Jul Jensen <julle@typo3.org>
 * @author Ingo Renner <ingo@typo3.org>
 */
class SchedulerModuleController extends \TYPO3\CMS\Backend\Module\BaseScriptClass {

	/**
	 * Back path to typo3 main dir
	 *
	 * @var string
	 */
	public $backPath;

	/**
	 * Array containing submitted data when editing or adding a task
	 *
	 * @var array
	 */
	protected $submittedData = array();

	/**
	 * Array containing all messages issued by the application logic
	 * Contains the error's severity and the message itself
	 *
	 * @var array
	 */
	protected $messages = array();

	/**
	 * @var string Key of the CSH file
	 */
	protected $cshKey;

	/**
	 * @var \TYPO3\CMS\Scheduler\Scheduler Local scheduler instance
	 */
	protected $scheduler;

	/**
	 * @var \TYPO3\CMS\Core\Page\PageRenderer
	 */
	protected $pageRenderer;

	/**
	 * @var string
	 */
	protected $backendTemplatePath = '';

	/**
	 * @var \TYPO3\CMS\Fluid\View\StandaloneView
	 */
	protected $view;

	/**
	 * The name of the module
	 *
	 * @var string
	 */
	protected $moduleName = 'system_txschedulerM1';

	/**
	 * @return \TYPO3\CMS\Scheduler\Controller\SchedulerModuleController
	 */
	public function __construct() {
		$this->getLanguageService()->includeLLFile('EXT:scheduler/Resources/Private/Language/locallang.xlf');
		$this->MCONF = array(
			'name' => $this->moduleName,
		);
		$this->backPath = $GLOBALS['BACK_PATH'];
		$this->cshKey = '_MOD_' . $this->moduleName;
		$this->backendTemplatePath = ExtensionManagementUtility::extPath('scheduler') . 'Resources/Private/Templates/Backend/SchedulerModule/';
		$this->view = GeneralUtility::makeInstance(\TYPO3\CMS\Fluid\View\StandaloneView::class);
		$this->view->getRequest()->setControllerExtensionName('scheduler');
	}

	/**
	 * Initializes the backend module
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		// Initialize document
		$this->doc = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Template\DocumentTemplate::class);
		$this->doc->setModuleTemplate(ExtensionManagementUtility::extPath('scheduler') . 'Resources/Private/Templates/Module.html');
		$this->doc->backPath = $this->backPath;
		$this->doc->bodyTagId = 'typo3-mod-php';
		$this->doc->bodyTagAdditions = 'class="tx_scheduler_mod1"';

		$this->pageRenderer = $this->doc->getPageRenderer();
		$this->pageRenderer->addCssFile(ExtensionManagementUtility::extRelPath('scheduler') . 'Resources/Public/Styles/styles.css');

		// Create scheduler instance
		$this->scheduler = GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Scheduler::class);
	}

	/**
	 * Adds items to the ->MOD_MENU array. Used for the function menu selector.
	 *
	 * @return void
	 */
	public function menuConfig() {
		$this->MOD_MENU = array(
			'function' => array(
				'scheduler' => $this->getLanguageService()->getLL('function.scheduler'),
				'check' => $this->getLanguageService()->getLL('function.check'),
				'info' => $this->getLanguageService()->getLL('function.info')
			)
		);
		parent::menuConfig();
	}

	/**
	 * Main function of the module. Write the content to $this->content
	 *
	 * @return void
	 */
	public function main() {
		// Access check!
		// The page will show only if user has admin rights
		if ($this->getBackendUserAuthentication()->isAdmin()) {
			// Set the form
			$this->doc->form = '<form name="tx_scheduler_form" id="tx_scheduler_form" method="post" action="">';
			$this->pageRenderer->addInlineSetting('scheduler', 'runningIcon', ExtensionManagementUtility::extRelPath('scheduler') . 'Resources/Public/Images/status_running.png');

			// Prepare main content
			$this->content = $this->doc->header($this->getLanguageService()->getLL('function.' . $this->MOD_SETTINGS['function']));
			$this->content .= $this->getModuleContent();
		} else {
			// If no access, only display the module's title
			$this->content = $this->doc->header($this->getLanguageService()->getLL('title'));
			$this->content .= $this->doc->spacer(5);
		}
		// Place content inside template
		$content = $this->doc->moduleBody(array(), $this->getDocHeaderButtons(), $this->getTemplateMarkers());
		// Renders the module page
		$this->content = $this->doc->render($this->getLanguageService()->getLL('title'), $content);
	}

	/**
	 * Generate the module's content
	 *
	 * @return string HTML of the module's main content
	 */
	protected function getModuleContent() {
		$content = '';
		$sectionTitle = '';
		// Get submitted data
		$this->submittedData = GeneralUtility::_GPmerged('tx_scheduler');
		$this->submittedData['uid'] = (int)$this->submittedData['uid'];
		// If a save command was submitted, handle saving now
		if ($this->CMD === 'save' || $this->CMD === 'saveclose' || $this->CMD === 'savenew') {
			$previousCMD = GeneralUtility::_GP('previousCMD');
			// First check the submitted data
			$result = $this->preprocessData();
			// If result is ok, proceed with saving
			if ($result) {
				$this->saveTask();
				if ($this->CMD === 'saveclose') {
					// Unset command, so that default screen gets displayed
					unset($this->CMD);
				} elseif ($this->CMD === 'save') {
					// After saving a "add form", return to edit
					$this->CMD = 'edit';
				} elseif ($this->CMD === 'savenew') {
					// Unset submitted data, so that empty form gets displayed
					unset($this->submittedData);
					// After saving a "add/edit form", return to add
					$this->CMD = 'add';
				} else {
					// Return to edit form
					$this->CMD = $previousCMD;
				}
			} else {
				$this->CMD = $previousCMD;
			}
		}

		// Handle chosen action
		switch ((string)$this->MOD_SETTINGS['function']) {
			case 'scheduler':
				$this->executeTasks();

				switch ($this->CMD) {
					case 'add':
					case 'edit':
						try {
							// Try adding or editing
							$content .= $this->editTaskAction();
							$sectionTitle = $this->getLanguageService()->getLL('action.' . $this->CMD);
						} catch (\Exception $e) {
							if ($e->getCode() === 1305100019) {
								// Invalid controller class name exception
								$this->addMessage($e->getMessage(), FlashMessage::ERROR);
							}
							// An exception may also happen when the task to
							// edit could not be found. In this case revert
							// to displaying the list of tasks
							// It can also happen when attempting to edit a running task
							$content .= $this->listTasksAction();
						}
						break;
					case 'delete':
						$this->deleteTask();
						$content .= $this->listTasksAction();
						break;
					case 'stop':
						$this->stopTask();
						$content .= $this->listTasksAction();
						break;
					case 'toggleHidden':
						$this->toggleDisableAction();
						$content .= $this->listTasksAction();
						break;
					case 'list':

					default:
						$content .= $this->listTasksAction();
				}
				break;

			// Setup check screen
			case 'check':
				// @todo move check to the report module
				$content .= $this->checkScreenAction();
				break;

			// Information screen
			case 'info':
				$content .= $this->infoScreenAction();
				break;
		}
		// Wrap the content in a section
		return $this->doc->section($sectionTitle, '<div class="tx_scheduler_mod1">' . $content . '</div>', FALSE, TRUE);
	}

	/**
	 * This method actually prints out the module's HTML content
	 *
	 * @return void
	 */
	public function render() {
		echo $this->content;
	}

	/**
	 * This method checks the status of the '_cli_scheduler' user
	 * It will differentiate between a non-existing user and an existing,
	 * but disabled user (as per enable fields)
	 *
	 * @return int -1 If user doesn't exist, 0 If user exist but not enabled, 1 If user exists and is enabled
	 */
	protected function checkSchedulerUser() {
		$schedulerUserStatus = -1;
		// Assemble base WHERE clause
		$where = 'username = \'_cli_scheduler\' AND admin = 0' . BackendUtility::deleteClause('be_users');
		// Check if user exists at all
		$res = $this->getDatabaseConnection()->exec_SELECTquery('1', 'be_users', $where);
		if ($this->getDatabaseConnection()->sql_fetch_assoc($res)) {
			$schedulerUserStatus = 0;
			$this->getDatabaseConnection()->sql_free_result($res);
			// Check if user exists and is enabled
			$res = $this->getDatabaseConnection()->exec_SELECTquery('1', 'be_users', $where . BackendUtility::BEenableFields('be_users'));
			if ($this->getDatabaseConnection()->sql_fetch_assoc($res)) {
				$schedulerUserStatus = 1;
			}
		}
		$this->getDatabaseConnection()->sql_free_result($res);
		return $schedulerUserStatus;
	}

	/**
	 * This method creates the "cli_scheduler" BE user if it doesn't exist
	 *
	 * @return void
	 */
	protected function createSchedulerUser() {
		// Check _cli_scheduler user status
		$checkUser = $this->checkSchedulerUser();
		// Prepare default message
		$message = $this->getLanguageService()->getLL('msg.userExists');
		$severity = FlashMessage::WARNING;
		// If the user does not exist, try creating it
		if ($checkUser == -1) {
			// Prepare necessary data for _cli_scheduler user creation
			$password = uniqid('scheduler', TRUE);
			if (SaltedPasswordsUtility::isUsageEnabled()) {
				$objInstanceSaltedPW = SaltFactory::getSaltingInstance();
				$password = $objInstanceSaltedPW->getHashedPassword($password);
			}
			$data = array('be_users' => array('NEW' => array('username' => '_cli_scheduler', 'password' => $password, 'pid' => 0)));
			/** @var $tcemain \TYPO3\CMS\Core\DataHandling\DataHandler */
			$tcemain = GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
			$tcemain->stripslashes_values = 0;
			$tcemain->start($data, array());
			$tcemain->process_datamap();
			// Check if a new uid was indeed generated (i.e. a new record was created)
			// (counting TCEmain errors doesn't work as some failures don't report errors)
			$numberOfNewIDs = count($tcemain->substNEWwithIDs);
			if ($numberOfNewIDs == 1) {
				$message = $this->getLanguageService()->getLL('msg.userCreated');
				$severity = FlashMessage::OK;
			} else {
				$message = $this->getLanguageService()->getLL('msg.userNotCreated');
				$severity = FlashMessage::ERROR;
			}
		}
		$this->addMessage($message, $severity);
	}

	/**
	 * This method displays the result of a number of checks
	 * on whether the Scheduler is ready to run or running properly
	 *
	 * @return string Further information
	 */
	protected function checkScreenAction() {
		$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'CheckScreen.html');

		// First, check if _cli_scheduler user creation was requested
		if ($this->CMD === 'user') {
			$this->createSchedulerUser();
		}

		// Display information about last automated run, as stored in the system registry
		/** @var $registry \TYPO3\CMS\Core\Registry */
		$registry = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Registry::class);
		$lastRun = $registry->get('tx_scheduler', 'lastRun');
		if (!is_array($lastRun)) {
			$message = $this->getLanguageService()->getLL('msg.noLastRun');
			$severity = FlashMessage::WARNING;
		} else {
			if (empty($lastRun['end']) || empty($lastRun['start']) || empty($lastRun['type'])) {
				$message = $this->getLanguageService()->getLL('msg.incompleteLastRun');
				$severity = FlashMessage::WARNING;
			} else {
				$startDate = date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $lastRun['start']);
				$startTime = date($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'], $lastRun['start']);
				$endDate = date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $lastRun['end']);
				$endTime = date($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'], $lastRun['end']);
				$label = 'automatically';
				if ($lastRun['type'] === 'manual') {
					$label = 'manually';
				}
				$type = $this->getLanguageService()->getLL('label.' . $label);
				$message = sprintf($this->getLanguageService()->getLL('msg.lastRun'), $type, $startDate, $startTime, $endDate, $endTime);
				$severity = FlashMessage::INFO;
			}
		}
		/** @var $flashMessage FlashMessage */
		$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, $message, '', $severity);
		$this->view->assign('lastRun', $flashMessage->render());

		// Check CLI user
		$checkUser = $this->checkSchedulerUser();
		if ($checkUser == -1) {
			$link = $GLOBALS['MCONF']['_'] . '&SET[function]=check&CMD=user';
			$message = sprintf($this->getLanguageService()->getLL('msg.schedulerUserMissing'), htmlspecialchars($link));
			$severity = FlashMessage::ERROR;
		} elseif ($checkUser == 0) {
			$message = $this->getLanguageService()->getLL('msg.schedulerUserFoundButDisabled');
			$severity = FlashMessage::WARNING;
		} else {
			$message = $this->getLanguageService()->getLL('msg.schedulerUserFound');
			$severity = FlashMessage::OK;
		}
		$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, $message, '', $severity);
		$this->view->assign('cliUser', $flashMessage->render());

		// Check if CLI script is executable or not
		$script = PATH_typo3 . 'cli_dispatch.phpsh';
		$this->view->assign('script', $script);

		// Skip this check if running Windows, as rights do not work the same way on this platform
		// (i.e. the script will always appear as *not* executable)
		if (TYPO3_OS === 'WIN') {
			$isExecutable = TRUE;
		} else {
			$isExecutable = is_executable($script);
		}
		if ($isExecutable) {
			$message = $this->getLanguageService()->getLL('msg.cliScriptExecutable');
			$severity = FlashMessage::OK;
		} else {
			$message = $this->getLanguageService()->getLL('msg.cliScriptNotExecutable');
			$severity = FlashMessage::ERROR;
		}
		$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, $message, '', $severity);
		$this->view->assign('isExecutable', $flashMessage->render());

		return $this->view->render();
	}

	/**
	 * This method gathers information about all available task classes and displays it
	 *
	 * @return string html
	 */
	protected function infoScreenAction() {
		$registeredClasses = $this->getRegisteredClasses();
		// No classes available, display information message
		if (count($registeredClasses) == 0) {
			$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'InfoScreenNoClasses.html');
			return $this->view->render();
		}

		$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'InfoScreen.html');
		$this->view->assign('registeredClasses', $registeredClasses);

		return $this->view->render();
	}

	/**
	 * Renders the task progress bar.
	 *
	 * @param float $progress Task progress
	 * @return string Progress bar markup
	 */
	protected function renderTaskProgressBar($progress) {
		$progressText = $this->getLanguageService()->getLL('status.progress') . ':&nbsp;' . $progress . '%';
		return '<div class="progress">'
		. '<div class="progress-bar progress-bar-striped" role="progressbar" aria-valuenow="' . $progress . '" aria-valuemin="0" aria-valuemax="100" style="width: ' . $progress . '%;">' . $progressText . '</div>'
		. '</div>';
	}

	/**
	 * Delete a task from the execution queue
	 *
	 * @return void
	 */
	protected function deleteTask() {
		try {
			// Try to fetch the task and delete it
			$task = $this->scheduler->fetchTask($this->submittedData['uid']);
			// If the task is currently running, it may not be deleted
			if ($task->isExecutionRunning()) {
				$this->addMessage($this->getLanguageService()->getLL('msg.maynotDeleteRunningTask'), FlashMessage::ERROR);
			} else {
				if ($this->scheduler->removeTask($task)) {
					$this->getBackendUserAuthentication()->writeLog(4, 0, 0, 0, 'Scheduler task "%s" (UID: %s, Class: "%s") was deleted', array($task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()));
					$this->addMessage($this->getLanguageService()->getLL('msg.deleteSuccess'));
				} else {
					$this->addMessage($this->getLanguageService()->getLL('msg.deleteError'), FlashMessage::ERROR);
				}
			}
		} catch (\UnexpectedValueException $e) {
			// The task could not be unserialized properly, simply delete the database record
			$result = $this->getDatabaseConnection()->exec_DELETEquery('tx_scheduler_task', 'uid = ' . (int)$this->submittedData['uid']);
			if ($result) {
				$this->addMessage($this->getLanguageService()->getLL('msg.deleteSuccess'));
			} else {
				$this->addMessage($this->getLanguageService()->getLL('msg.deleteError'), FlashMessage::ERROR);
			}
		} catch (\OutOfBoundsException $e) {
			// The task was not found, for some reason
			$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.taskNotFound'), $this->submittedData['uid']), FlashMessage::ERROR);
		}
	}

	/**
	 * Clears the registered running executions from the task
	 * Note that this doesn't actually stop the running script. It just unmarks
	 * all executions.
	 * @todo find a way to really kill the running task
	 *
	 * @return void
	 */
	protected function stopTask() {
		try {
			// Try to fetch the task and stop it
			$task = $this->scheduler->fetchTask($this->submittedData['uid']);
			if ($task->isExecutionRunning()) {
				// If the task is indeed currently running, clear marked executions
				$result = $task->unmarkAllExecutions();
				if ($result) {
					$this->addMessage($this->getLanguageService()->getLL('msg.stopSuccess'));
				} else {
					$this->addMessage($this->getLanguageService()->getLL('msg.stopError'), FlashMessage::ERROR);
				}
			} else {
				// The task is not running, nothing to unmark
				$this->addMessage($this->getLanguageService()->getLL('msg.maynotStopNonRunningTask'), FlashMessage::WARNING);
			}
		} catch (\Exception $e) {
			// The task was not found, for some reason
			$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.taskNotFound'), $this->submittedData['uid']), FlashMessage::ERROR);
		}
	}

	/**
	 * Toggles the disabled state of the submitted task
	 *
	 * @return void
	 */
	protected function toggleDisableAction() {
		$task = $this->scheduler->fetchTask($this->submittedData['uid']);
		$task->setDisabled(!$task->isDisabled());
		$task->save();
	}

	/**
	 * Return a form to add a new task or edit an existing one
	 *
	 * @return string HTML form to add or edit a task
	 */
	protected function editTaskAction() {
		$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'EditTask.html');

		$registeredClasses = $this->getRegisteredClasses();
		$registeredTaskGroups = $this->getRegisteredTaskGroups();

		$taskInfo = array();
		$task = NULL;
		$process = 'edit';

		if ($this->submittedData['uid'] > 0) {
			// If editing, retrieve data for existing task
			try {
				$taskRecord = $this->scheduler->fetchTaskRecord($this->submittedData['uid']);
				// If there's a registered execution, the task should not be edited
				if (!empty($taskRecord['serialized_executions'])) {
					$this->addMessage($this->getLanguageService()->getLL('msg.maynotEditRunningTask'), FlashMessage::ERROR);
					throw new \LogicException('Runnings tasks cannot not be edited', 1251232849);
				}

				// Get the task object
				/** @var $task \TYPO3\CMS\Scheduler\Task\AbstractTask */
				$task = unserialize($taskRecord['serialized_task_object']);

				// Set some task information
				$taskInfo['disable'] = $taskRecord['disable'];
				$taskInfo['description'] = $taskRecord['description'];
				$taskInfo['task_group'] = $taskRecord['task_group'];

				// Check that the task object is valid
				if ($this->scheduler->isValidTaskObject($task)) {
					// The task object is valid, process with fetching current data
					$taskInfo['class'] = get_class($task);
					// Get execution information
					$taskInfo['start'] = (int)$task->getExecution()->getStart();
					$taskInfo['end'] = (int)$task->getExecution()->getEnd();
					$taskInfo['interval'] = $task->getExecution()->getInterval();
					$taskInfo['croncmd'] = $task->getExecution()->getCronCmd();
					$taskInfo['multiple'] = $task->getExecution()->getMultiple();
					if (!empty($taskInfo['interval']) || !empty($taskInfo['croncmd'])) {
						// Guess task type from the existing information
						// If an interval or a cron command is defined, it's a recurring task
						// @todo remove magic numbers for the type, use class constants instead
						$taskInfo['type'] = 2;
						$taskInfo['frequency'] = $taskInfo['interval'] ?: $taskInfo['croncmd'];
					} else {
						// It's not a recurring task
						// Make sure interval and cron command are both empty
						$taskInfo['type'] = 1;
						$taskInfo['frequency'] = '';
						$taskInfo['end'] = 0;
					}
				} else {
					// The task object is not valid
					// Issue error message
					$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.invalidTaskClassEdit'), get_class($task)), FlashMessage::ERROR);
					// Initialize empty values
					$taskInfo['start'] = 0;
					$taskInfo['end'] = 0;
					$taskInfo['frequency'] = '';
					$taskInfo['multiple'] = FALSE;
					$taskInfo['type'] = 1;
				}
			} catch (\OutOfBoundsException $e) {
				// Add a message and continue throwing the exception
				$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.taskNotFound'), $this->submittedData['uid']), FlashMessage::ERROR);
				throw $e;
			}
		} else {
			// If adding a new object, set some default values
			$taskInfo['class'] = key($registeredClasses);
			$taskInfo['type'] = 2;
			$taskInfo['start'] = $GLOBALS['EXEC_TIME'];
			$taskInfo['end'] = '';
			$taskInfo['frequency'] = '';
			$taskInfo['multiple'] = 0;
			$process = 'add';
		}

		// If some data was already submitted, use it to override
		// existing data
		if (count($this->submittedData) > 0) {
			\TYPO3\CMS\Core\Utility\ArrayUtility::mergeRecursiveWithOverrule($taskInfo, $this->submittedData);
		}

		// Get the extra fields to display for each task that needs some
		$allAdditionalFields = array();
		if ($process === 'add') {
			foreach ($registeredClasses as $class => $registrationInfo) {
				if (!empty($registrationInfo['provider'])) {
					/** @var $providerObject \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface */
					$providerObject = GeneralUtility::getUserObj($registrationInfo['provider']);
					if ($providerObject instanceof \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface) {
						$additionalFields = $providerObject->getAdditionalFields($taskInfo, NULL, $this);
						$allAdditionalFields = array_merge($allAdditionalFields, array($class => $additionalFields));
					}
				}
			}
		} else {
			if (!empty($registeredClasses[$taskInfo['class']]['provider'])) {
				$providerObject = GeneralUtility::getUserObj($registeredClasses[$taskInfo['class']]['provider']);
				if ($providerObject instanceof \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface) {
					$allAdditionalFields[$taskInfo['class']] = $providerObject->getAdditionalFields($taskInfo, $task, $this);
				}
			}
		}

		// Load necessary JavaScript
		$this->pageRenderer->loadJquery();
		$this->pageRenderer->loadRequireJsModule('TYPO3/CMS/Scheduler/Scheduler');
		$this->pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/DateTimePicker');

		// Start rendering the add/edit form
		$this->view->assign('uid', htmlspecialchars($this->submittedData['uid']));
		$this->view->assign('cmd', htmlspecialchars($this->CMD));

		$table = array();

		// Disable checkbox
		$label = '<label for="task_disable">' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:disable') . '</label>';
		$table[] = '<div id="task_disable_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_disable', $label) .
			'<input type="hidden" name="tx_scheduler[disable]" value="0" />
						<input class="checkbox" type="checkbox" name="tx_scheduler[disable]" value="1" id="task_disable" ' . ($taskInfo['disable'] == 1 ? ' checked="checked"' : '') . ' />
					</div>';

		// Task class selector
		$label = '<label for="task_class">' . $this->getLanguageService()->getLL('label.class') . '</label>';

		// On editing, don't allow changing of the task class, unless it was not valid
		if ($this->submittedData['uid'] > 0 && !empty($taskInfo['class'])) {
			$cell = '<div>' . $registeredClasses[$taskInfo['class']]['title'] . ' (' . $registeredClasses[$taskInfo['class']]['extension'] . ')</div>';
			$cell .= '<input type="hidden" name="tx_scheduler[class]" id="task_class" value="' . htmlspecialchars($taskInfo['class']) . '" />';
		} else {
			$cell = '<select name="tx_scheduler[class]" id="task_class" class="form-control">';
			// Group registered classes by classname
			$groupedClasses = array();
			foreach ($registeredClasses as $class => $classInfo) {
				$groupedClasses[$classInfo['extension']][$class] = $classInfo;
			}
			ksort($groupedClasses);
			// Loop on all grouped classes to display a selector
			foreach ($groupedClasses as $extension => $class) {
				$cell .= '<optgroup label="' . htmlspecialchars($extension) . '">';
				foreach ($groupedClasses[$extension] as $class => $classInfo) {
					$selected = $class == $taskInfo['class'] ? ' selected="selected"' : '';
					$cell .= '<option value="' . $class . '"' . 'title="' . htmlspecialchars($classInfo['description']) . '" ' . $selected . '>' . htmlspecialchars($classInfo['title']) . '</option>';
				}
				$cell .= '</optgroup>';
			}
			$cell .= '</select>';
		}
		$table[] = '<div id="task_class_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_class', $label) .
			$cell .
			'</div>';

		// Task type selector
		$label = '<label for="task_type">' . $this->getLanguageService()->getLL('label.type') . '</label>';
		$table[] = '<div id="task_type_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_type', $label) .
			'<select name="tx_scheduler[type]" id="task_type" class="form-control">
							<option value="1" ' . ($taskInfo['type'] == 1 ? ' selected="selected"' : '') . '>' . $this->getLanguageService()->getLL('label.type.single') . '</option>
							<option value="2" ' . ($taskInfo['type'] == 2 ? ' selected="selected"' : '') . '>' . $this->getLanguageService()->getLL('label.type.recurring') . '</option>
						</select>
					</div>';

		// Task group selector
		$label = '<label for="task_group">' . $this->getLanguageService()->getLL('label.group') . '</label>';
		$cell = '<select name="tx_scheduler[task_group]" id="task_class" class="form-control">';

		// Loop on all groups to display a selector
		$cell .= '<option value="0" title=""></option>';
		foreach ($registeredTaskGroups as $taskGroup) {
			$selected = $taskGroup['uid'] == $taskInfo['task_group'] ? ' selected="selected"' : '';
			$cell .= '<option value="' . $taskGroup['uid'] . '"' . 'title="';
			$cell .= htmlspecialchars($taskGroup['groupName']) . '"' . $selected . '>';
			$cell .= htmlspecialchars($taskGroup['groupName']) . '</option>';
		}
		$cell .= '</select>';

		$table[] = '<div id="task_group_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_group', $label) .
			$cell .
			'</div>';

		$dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['USdateFormat'] ? '%H:%M %m-%d-%Y' : '%H:%M %d-%m-%Y';

		$label = '<label for="tceforms-datetimefield-task_start">' . BackendUtility::wrapInHelp($this->cshKey, 'task_start', $this->getLanguageService()->getLL('label.start')) . '</label>';
		$value = ($taskInfo['start'] > 0 ? strftime($dateFormat, $taskInfo['start']) : '');
		$table[] = '<div class="form-group">' .
			$label .
			'<div class="input-group" id="tceforms-datetimefield-task_start_row-wrapper">' .
			'<input name="tx_scheduler[start]_hr" value="' . $value . '" class="form-control t3js-datetimepicker t3js-clearable" data-date-type="datetime" data-date-offset="0" type="text" ' .
			'id="tceforms-datetimefield-task_start_row">' .
			'<input name="tx_scheduler[start]" value="' . $taskInfo['start'] . '" type="hidden">' .
			'<span class="input-group-btn"><label class="btn btn-default" for="tceforms-datetimefield-task_start_row"><span class="fa fa-calendar"></span></label></span>' .
			'</div>' .
			'</div>';


		// End date/time field
		// NOTE: datetime fields need a special id naming scheme
		$value = ($taskInfo['end'] > 0 ? strftime($dateFormat, $taskInfo['end']) : '');
		$label = '<label for="tceforms-datetimefield-task_end">' . $this->getLanguageService()->getLL('label.end') . '</label>';
		$table[] = '<div class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_end', $label) .
			'<div class="input-group" id="tceforms-datetimefield-task_end_row-wrapper">' .
			'<input name="tx_scheduler[end]_hr" value="' . $value . '" class="form-control  t3js-datetimepicker t3js-clearable" data-date-type="datetime" data-date-offset="0" type="text" ' .
			'id="tceforms-datetimefield-task_end_row">' .
			'<input name="tx_scheduler[end]" value="' . $taskInfo['end'] . '" type="hidden">' .
			'<span class="input-group-btn"><label class="btn btn-default" for="tceforms-datetimefield-task_end_row"><span class="fa fa-calendar"></span></label></span>' .
			'</div>' .
			'</div>';

		// Frequency input field
		$label = '<label for="task_frequency">' . $this->getLanguageService()->getLL('label.frequency.long') . '</label>';
		$table[] = '<div id="task_frequency_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_frequency', $label) .
			'<input type="text" name="tx_scheduler[frequency]" class="form-control" id="task_frequency" value="' . htmlspecialchars($taskInfo['frequency']) . '" />
					</div>';

		// Multiple execution selector
		$label = '<label for="task_multiple">' . $this->getLanguageService()->getLL('label.parallel.long') . '</label>';
		$table[] = '<div id="task_multiple_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_multiple', $label) .
			'<input type="hidden"   name="tx_scheduler[multiple]" value="0" />
						<input class="checkbox" type="checkbox" name="tx_scheduler[multiple]" value="1" id="task_multiple" ' . ($taskInfo['multiple'] == 1 ? 'checked="checked"' : '') . ' />
					</div>';

		// Description
		$label = '<label for="task_description">' . $this->getLanguageService()->getLL('label.description') . '</label>';
		$table[] = '<div id="task_description_row" class="form-group">' .
			BackendUtility::wrapInHelp($this->cshKey, 'task_description', $label) .
			'<textarea class="form-control" name="tx_scheduler[description]">' . htmlspecialchars($taskInfo['description']) . '</textarea>
					</div>';

		// Display additional fields
		foreach ($allAdditionalFields as $class => $fields) {
			if ($class == $taskInfo['class']) {
				$additionalFieldsStyle = '';
			} else {
				$additionalFieldsStyle = ' style="display: none"';
			}
			// Add each field to the display, if there are indeed any
			if (isset($fields) && is_array($fields)) {
				foreach ($fields as $fieldID => $fieldInfo) {
					$label = '<label for="' . $fieldID . '">' . $this->getLanguageService()->sL($fieldInfo['label']) . '</label>';
					$htmlClassName = strtolower(str_replace('\\', '-', $class));
					$table[] = '<div id="' . $fieldID . '_row"' . $additionalFieldsStyle . ' class="form-group extraFields extra_fields_' . $htmlClassName . '">' .
						BackendUtility::wrapInHelp($fieldInfo['cshKey'], $fieldInfo['cshLabel'], $label) .
						'<div>' . $fieldInfo['code'] . '</div>
								</div>';
				}
			}
		}

		$this->view->assign('table', implode(LF, $table));

		// Server date time
		$dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] . ' T (e';
		$this->view->assign('now', date($dateFormat) . ', GMT ' . date('P') . ')');

		return $this->view->render();
	}

	/**
	 * Execute all selected tasks
	 *
	 * @return void
	 */
	protected function executeTasks() {
		// Make sure next automatic scheduler-run is scheduled
		if (GeneralUtility::_POST('go') !== NULL) {
			$this->scheduler->scheduleNextSchedulerRunUsingAtDaemon();
		}
		// Continue if some elements have been chosen for execution
		if (isset($this->submittedData['execute']) && count($this->submittedData['execute']) > 0) {
			// Get list of registered classes
			$registeredClasses = $this->getRegisteredClasses();
			// Loop on all selected tasks
			foreach ($this->submittedData['execute'] as $uid) {
				try {
					// Try fetching the task
					$task = $this->scheduler->fetchTask($uid);
					$class = get_class($task);
					$name = $registeredClasses[$class]['title'] . ' (' . $registeredClasses[$class]['extension'] . ')';
					// Now try to execute it and report on outcome
					try {
						$result = $this->scheduler->executeTask($task);
						if ($result) {
							$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.executed'), $name));
						} else {
							$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.notExecuted'), $name), FlashMessage::ERROR);
						}
					} catch (\Exception $e) {
						// An exception was thrown, display its message as an error
						$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.executionFailed'), $name, $e->getMessage()), FlashMessage::ERROR);
					}
				} catch (\OutOfBoundsException $e) {
					$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.taskNotFound'), $uid), FlashMessage::ERROR);
				} catch (\UnexpectedValueException $e) {
					$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.executionFailed'), $uid, $e->getMessage()), FlashMessage::ERROR);
				}
			}
			// Record the run in the system registry
			$this->scheduler->recordLastRun('manual');
			// Make sure to switch to list view after execution
			$this->CMD = 'list';
		}
	}

	/**
	 * Assemble display of list of scheduled tasks
	 *
	 * @return string Table of pending tasks
	 */
	protected function listTasksAction() {
		$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'ListTasks.html');

		// Define display format for dates
		$dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];

		// Get list of registered classes
		$registeredClasses = $this->getRegisteredClasses();
		// Get list of registered task groups
		$registeredTaskGroups = $this->getRegisteredTaskGroups();

		// add an empty entry for non-grouped tasks
		// add in front of list
		array_unshift($registeredTaskGroups, array('uid' => 0, 'groupName' => ''));

		// Get all registered tasks
		// Just to get the number of entries
		$query = array(
			'SELECT' => '
				tx_scheduler_task.*,
				tx_scheduler_task_group.groupName as taskGroupName,
				tx_scheduler_task_group.description as taskGroupDescription,
				tx_scheduler_task_group.deleted as isTaskGroupDeleted
				',
			'FROM' => '
				tx_scheduler_task
				LEFT JOIN tx_scheduler_task_group ON tx_scheduler_task_group.uid = tx_scheduler_task.task_group
				',
			'WHERE' => '1=1',
			'ORDERBY' => 'tx_scheduler_task_group.sorting'
		);
		$res = $this->getDatabaseConnection()->exec_SELECT_queryArray($query);
		$numRows = $this->getDatabaseConnection()->sql_num_rows($res);

		// No tasks defined, display information message
		if ($numRows == 0) {
			$this->view->setTemplatePathAndFilename($this->backendTemplatePath . 'ListTasksNoTasks.html');

			/** @var $flashMessage FlashMessage */
			$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, $this->getLanguageService()->getLL('msg.noTasks'), '', FlashMessage::INFO);
			$this->view->assign('message', $flashMessage->render());
			return $this->view->render();
		} else {
			$this->pageRenderer->loadJquery();
			$this->pageRenderer->loadRequireJsModule('TYPO3/CMS/Scheduler/Scheduler');
			$table = array();
			// Header row
			$table[] =  '<thead><tr>
							<td><a href="#" id="checkall" title="' . $this->getLanguageService()->getLL('label.checkAll', TRUE) . '" class="icon">' . IconUtility::getSpriteIcon('actions-document-select') . '</a></td>
							<td>&nbsp;</td>
							<td>' . $this->getLanguageService()->getLL('label.id', TRUE). '</td>
							<td colspan="2">' . $this->getLanguageService()->getLL('task', TRUE). '</td>
							<td>' . $this->getLanguageService()->getLL('label.type', TRUE). '</td>
							<td>' . $this->getLanguageService()->getLL('label.frequency', TRUE). '</td>
							<td>' . $this->getLanguageService()->getLL('label.parallel', TRUE). '</td>
							<td>' . $this->getLanguageService()->getLL('label.lastExecution', TRUE). '</td>
							<td>' . $this->getLanguageService()->getLL('label.nextExecution', TRUE). '</td>
						</tr></thead>';

			// Loop on all tasks
			$temporaryResult = array();
			while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
				if ($row['taskGroupName'] === NULL || $row['isTaskGroupDeleted'] === '1') {
					$row['taskGroupName'] = '';
					$row['taskGroupDescription'] = '';
					$row['task_group'] = 0;
				}
				$temporaryResult[$row['task_group']]['groupName'] = $row['taskGroupName'];
				$temporaryResult[$row['task_group']]['groupDescription'] = $row['taskGroupDescription'];
				$temporaryResult[$row['task_group']]['tasks'][] = $row;
			}
			foreach ($temporaryResult as $taskGroup) {
				$groupText = '<strong>' . htmlspecialchars($taskGroup['groupName']) . '</strong>';
					if (!empty($taskGroup['groupDescription'])) {
						$groupText .= '<br />' . nl2br(htmlspecialchars($taskGroup['groupDescription']));
					}
					$table[] = '<tr><td colspan="10">' . $groupText . '</td></tr>';


				foreach ($taskGroup['tasks'] as $schedulerRecord) {// Define action icons
					$editAction = '<a href="' . $GLOBALS['MCONF']['_'] . '&CMD=edit&tx_scheduler[uid]=' . $schedulerRecord['uid'] . '" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:edit', TRUE) . '" class="icon">' .
						IconUtility::getSpriteIcon('actions-document-open') . '</a>';
					if ((int)$schedulerRecord['disable'] === 1) {
						$translationKey = 'enable';
						$spriteIcon = 'actions-edit-unhide';
					} else {
						$translationKey = 'disable';
						$spriteIcon = 'actions-edit-hide';
					}
					$toggleHiddenAction = '<a href="' . $GLOBALS['MCONF']['_'] . '&CMD=toggleHidden&tx_scheduler[uid]=' . $schedulerRecord['uid'] . '" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:' . $translationKey, TRUE) . '" class="icon">' .
						IconUtility::getSpriteIcon($spriteIcon) . '</a>';
					$deleteAction = '<a href="' . $GLOBALS['MCONF']['_'] . '&CMD=delete&tx_scheduler[uid]=' . $schedulerRecord['uid'] . '" onclick="return confirm(\'' . $this->getLanguageService()->getLL('msg.delete') . '\');" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:delete', TRUE) . '" class="icon">' .
						IconUtility::getSpriteIcon('actions-edit-delete') . '</a>';
					$stopAction = '<a href="' . $GLOBALS['MCONF']['_'] . '&CMD=stop&tx_scheduler[uid]=' . $schedulerRecord['uid'] . '" onclick="return confirm(\'' . $this->getLanguageService()->getLL('msg.stop') . '\');" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:stop', TRUE) . '" class="icon">' .
						'<img ' . IconUtility::skinImg($this->backPath, (ExtensionManagementUtility::extRelPath('scheduler') . '/Resources/Public/Images/stop.png')) . ' alt="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:stop') . '" /></a>';
					$runAction = '<a href="' . $GLOBALS['MCONF']['_'] . '&tx_scheduler[execute][]=' . $schedulerRecord['uid'] . '" title="' . $this->getLanguageService()->getLL('action.run_task') . '" class="icon">' .
						IconUtility::getSpriteIcon('extensions-scheduler-run-task') . '</a>';

					// Define some default values
					$lastExecution = '-';
					$isRunning = FALSE;
					$showAsDisabled = FALSE;
					$executionStatus = 'scheduled';
					$startExecutionElement = '&nbsp;';
					// Restore the serialized task and pass it a reference to the scheduler object
					/** @var $task \TYPO3\CMS\Scheduler\Task\AbstractTask|\TYPO3\CMS\Scheduler\ProgressProviderInterface */
					$task = unserialize($schedulerRecord['serialized_task_object']);
					$class = get_class($task);
					if ($class === '__PHP_Incomplete_Class' && preg_match('/^O:[0-9]+:"(?P<classname>.+?)"/', $schedulerRecord['serialized_task_object'], $matches) === 1) {
						$class = $matches['classname'];
					}
					// Assemble information about last execution
					if (!empty($schedulerRecord['lastexecution_time'])) {
						$lastExecution = date($dateFormat, $schedulerRecord['lastexecution_time']);
						if ($schedulerRecord['lastexecution_context'] == 'CLI') {
							$context = $this->getLanguageService()->getLL('label.cron');
						} else {
							$context = $this->getLanguageService()->getLL('label.manual');
						}
						$lastExecution .= ' (' . $context . ')';
					}

					if ($this->scheduler->isValidTaskObject($task)) {
						// The task object is valid
						$name = '<div class="title">' . htmlspecialchars($registeredClasses[$class]['title'] . ' (' . $registeredClasses[$class]['extension'] . ')') . '</div>';
						$additionalInformation = $task->getAdditionalInformation();
						if ($task instanceof \TYPO3\CMS\Scheduler\ProgressProviderInterface) {
							$progress = round(floatval($task->getProgress()), 2);
							$name .= $this->renderTaskProgressBar($progress);
						}
						if (!empty($additionalInformation)) {
							$name .= '<div class="additional-information">[' . htmlspecialchars($additionalInformation) . ']</div>';
						}
						// Check if task currently has a running execution
						if (!empty($schedulerRecord['serialized_executions'])) {
							$isRunning = TRUE;
							$executionStatus = 'running';
						}

						// Prepare display of next execution date
						// If task is currently running, date is not displayed (as next hasn't been calculated yet)
						// Also hide the date if task is disabled (the information doesn't make sense, as it will not run anyway)
						if ($isRunning || $schedulerRecord['disable'] == 1) {
							$nextDate = '-';
						} else {
							$nextDate = date($dateFormat, $schedulerRecord['nextexecution']);
							if (empty($schedulerRecord['nextexecution'])) {
								$nextDate = $this->getLanguageService()->getLL('none');
							} elseif ($schedulerRecord['nextexecution'] < $GLOBALS['EXEC_TIME']) {
								// Next execution is overdue, highlight date
								$nextDate = '<span class="late" title="' . $this->getLanguageService()->getLL('status.legend.scheduled') . '">' . $nextDate . '</span>';
								$executionStatus = 'late';
							}
						}
						// Get execution type
						if ($task->getExecution()->getInterval() == 0 && $task->getExecution()->getCronCmd() == '') {
							$execType = $this->getLanguageService()->getLL('label.type.single');
							$frequency = '-';
						} else {
							$execType = $this->getLanguageService()->getLL('label.type.recurring');
							if ($task->getExecution()->getCronCmd() == '') {
								$frequency = $task->getExecution()->getInterval();
							} else {
								$frequency = $task->getExecution()->getCronCmd();
							}
						}
						// Get multiple executions setting
						if ($task->getExecution()->getMultiple()) {
							$multiple = $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:yes');
						} else {
							$multiple = $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:no');
						}
						// Define checkbox
						$startExecutionElement = '<input type="checkbox" name="tx_scheduler[execute][]" value="' . $schedulerRecord['uid'] . '" id="task_' . $schedulerRecord['uid'] . '" class="checkboxes" />';

						$actions = $editAction . $toggleHiddenAction . $deleteAction;

						// Check the disable status
						// Row is shown dimmed if task is disabled, unless it is still running
						if ($schedulerRecord['disable'] == 1 && !$isRunning) {
							$showAsDisabled = TRUE;
							$executionStatus = 'disabled';
						}

						// Show no action links (edit, delete) if task is running
						if ($isRunning) {
							$actions = $stopAction;
						} else {
							$actions .= $runAction;
						}

						// Check if the last run failed
						$failureOutput = '';
						if (!empty($schedulerRecord['lastexecution_failure'])) {
							// Try to get the stored exception object
							/** @var $exception \Exception */
							$exception = unserialize($schedulerRecord['lastexecution_failure']);
							// If the exception could not be unserialized, issue a default error message
							if ($exception === FALSE || $exception instanceof \__PHP_Incomplete_Class) {
								$failureDetail = $this->getLanguageService()->getLL('msg.executionFailureDefault');
							} else {
								$failureDetail = sprintf($this->getLanguageService()->getLL('msg.executionFailureReport'), $exception->getCode(), $exception->getMessage());
							}
							$failureOutput = ' <img ' . IconUtility::skinImg(ExtensionManagementUtility::extRelPath('scheduler'), 'Resources/Public/Images/status_failure.png') . ' alt="' . htmlspecialchars($this->getLanguageService()->getLL('status.failure')) . '" title="' . htmlspecialchars($failureDetail) . '" />';
						}
						// Format the execution status,
						// including failure feedback, if any
						$executionStatusOutput = '<img ' . IconUtility::skinImg(ExtensionManagementUtility::extRelPath('scheduler'), ('Resources/Public/Images/status_' . $executionStatus . '.png')) . ' id="executionstatus_' . $schedulerRecord['uid'] . '" alt="' . htmlspecialchars($this->getLanguageService()->getLL(('status.' . $executionStatus))) . '" title="' . htmlspecialchars($this->getLanguageService()->getLL(('status.legend.' . $executionStatus))) . '" />' . $failureOutput;
						if ($schedulerRecord['description'] !== '') {
							$taskName = '<span title="' . htmlspecialchars($schedulerRecord['description']) . '">' . $name . '</span>';
						} else {
							$taskName = $name;
						}

						$table[] = '<tr class="' . ($showAsDisabled ? 'disabled' : '') . '">
										<td>' . $startExecutionElement . '</td>
										<td class="right">' . $actions . '</td>
										<td class="right">' . $schedulerRecord['uid'] . '</td>
										<td>' . $executionStatusOutput . '</td>
										<td>' . $taskName . '</td>
										<td>' . $execType . '</td>
										<td>' . $frequency . '</td>
										<td>' . $multiple . '</td>
										<td>' . $lastExecution . '</td>
										<td>' . $nextDate . '</td>
									</tr>';
					} else {
						// The task object is not valid
						// Prepare to issue an error
						/** @var $flashMessage FlashMessage */
						$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, sprintf($this->getLanguageService()->getLL('msg.invalidTaskClass'), $class), '', FlashMessage::ERROR);
						$executionStatusOutput = $flashMessage->render();
						$table[] = '<tr>
										<td>' . $startExecutionElement . '</td>
										<td class="right">' . $deleteAction . '</td>
										<td class="right">' . $schedulerRecord['uid'] . '</td>
										<td colspan="6">' . $executionStatusOutput . '</td>
								</tr>';
					}
				}
			}
			$this->getDatabaseConnection()->sql_free_result($res);

			$this->view->assign('table', '<table class="table table-striped table-hover">' . implode(LF, $table) . '</table>');

			// Server date time
			$dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] . ' T (e';
			$this->view->assign('now', date($dateFormat) . ', GMT ' . date('P') . ')');
		}

		return $this->view->render();
	}

	/**
	 * Saves a task specified in the backend form to the database
	 *
	 * @return void
	 */
	protected function saveTask() {
		// If a task is being edited fetch old task data
		if (!empty($this->submittedData['uid'])) {
			try {
				$taskRecord = $this->scheduler->fetchTaskRecord($this->submittedData['uid']);
				/** @var $task \TYPO3\CMS\Scheduler\Task\AbstractTask */
				$task = unserialize($taskRecord['serialized_task_object']);
			} catch (\OutOfBoundsException $e) {
				// If the task could not be fetched, issue an error message
				// and exit early
				$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.taskNotFound'), $this->submittedData['uid']), FlashMessage::ERROR);
				return;
			}
			// Register single execution
			if ($this->submittedData['type'] == 1) {
				$task->registerSingleExecution($this->submittedData['start']);
			} else {
				if (!empty($this->submittedData['croncmd'])) {
					// Definition by cron-like syntax
					$interval = 0;
					$cronCmd = $this->submittedData['croncmd'];
				} else {
					// Definition by interval
					$interval = $this->submittedData['interval'];
					$cronCmd = '';
				}
				// Register recurring execution
				$task->registerRecurringExecution($this->submittedData['start'], $interval, $this->submittedData['end'], $this->submittedData['multiple'], $cronCmd);
			}
			// Set disable flag
			$task->setDisabled($this->submittedData['disable']);
			// Set description
			$task->setDescription($this->submittedData['description']);
			// Set task group
			$task->setTaskGroup($this->submittedData['task_group']);
			// Save additional input values
			if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields'])) {
				/** @var $providerObject \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface */
				$providerObject = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields']);
				if ($providerObject instanceof \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface) {
					$providerObject->saveAdditionalFields($this->submittedData, $task);
				}
			}
			// Save to database
			$result = $this->scheduler->saveTask($task);
			if ($result) {
				$this->getBackendUserAuthentication()->writeLog(4, 0, 0, 0, 'Scheduler task "%s" (UID: %s, Class: "%s") was updated', array($task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()));
				$this->addMessage($this->getLanguageService()->getLL('msg.updateSuccess'));
			} else {
				$this->addMessage($this->getLanguageService()->getLL('msg.updateError'), FlashMessage::ERROR);
			}
		} else {
			// A new task is being created
			// Create an instance of chosen class
			/** @var $task AbstractTask */
			$task = GeneralUtility::makeInstance($this->submittedData['class']);
			if ($this->submittedData['type'] == 1) {
				// Set up single execution
				$task->registerSingleExecution($this->submittedData['start']);
			} else {
				// Set up recurring execution
				$task->registerRecurringExecution($this->submittedData['start'], $this->submittedData['interval'], $this->submittedData['end'], $this->submittedData['multiple'], $this->submittedData['croncmd']);
			}
			// Save additional input values
			if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields'])) {
				/** @var $providerObject \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface */
				$providerObject = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields']);
				if ($providerObject instanceof \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface) {
					$providerObject->saveAdditionalFields($this->submittedData, $task);
				}
			}
			// Set disable flag
			$task->setDisabled($this->submittedData['disable']);
			// Set description
			$task->setDescription($this->submittedData['description']);
			// Set description
			$task->setTaskGroup($this->submittedData['task_group']);
			// Add to database
			$result = $this->scheduler->addTask($task);
			if ($result) {
				$this->getBackendUserAuthentication()->writeLog(4, 0, 0, 0, 'Scheduler task "%s" (UID: %s, Class: "%s") was added', array($task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()));
				$this->addMessage($this->getLanguageService()->getLL('msg.addSuccess'));

				// set the uid of the just created task so that we
				// can continue editing after initial saving
				$this->submittedData['uid'] = $task->getTaskUid();
			} else {
				$this->addMessage($this->getLanguageService()->getLL('msg.addError'), FlashMessage::ERROR);
			}
		}
	}

	/*************************
	 *
	 * INPUT PROCESSING UTILITIES
	 *
	 *************************/
	/**
	 * Checks the submitted data and performs some pre-processing on it
	 *
	 * @return bool TRUE if everything was ok, FALSE otherwise
	 */
	protected function preprocessData() {
		$result = TRUE;
		// Validate id
		$this->submittedData['uid'] = empty($this->submittedData['uid']) ? 0 : (int)$this->submittedData['uid'];
		// Validate selected task class
		if (!class_exists($this->submittedData['class'])) {
			$this->addMessage($this->getLanguageService()->getLL('msg.noTaskClassFound'), FlashMessage::ERROR);
		}
		// Check start date
		if (empty($this->submittedData['start'])) {
			$this->addMessage($this->getLanguageService()->getLL('msg.noStartDate'), FlashMessage::ERROR);
			$result = FALSE;
		} else {
			try {
				$this->submittedData['start'] = (int)$this->submittedData['start'];
			} catch (\Exception $e) {
				$this->addMessage($this->getLanguageService()->getLL('msg.invalidStartDate'), FlashMessage::ERROR);
				$result = FALSE;
			}
		}
		// Check end date, if recurring task
		if ($this->submittedData['type'] == 2 && !empty($this->submittedData['end'])) {
			try {
				$this->submittedData['end'] = (int)$this->submittedData['end'];
				if ($this->submittedData['end'] < $this->submittedData['start']) {
					$this->addMessage($this->getLanguageService()->getLL('msg.endDateSmallerThanStartDate'), FlashMessage::ERROR);
					$result = FALSE;
				}
			} catch (\Exception $e) {
				$this->addMessage($this->getLanguageService()->getLL('msg.invalidEndDate'), FlashMessage::ERROR);
				$result = FALSE;
			}
		}
		// Set default values for interval and cron command
		$this->submittedData['interval'] = 0;
		$this->submittedData['croncmd'] = '';
		// Check type and validity of frequency, if recurring
		if ($this->submittedData['type'] == 2) {
			$frequency = trim($this->submittedData['frequency']);
			if (empty($frequency)) {
				// Empty frequency, not valid
				$this->addMessage($this->getLanguageService()->getLL('msg.noFrequency'), FlashMessage::ERROR);
				$result = FALSE;
			} else {
				$cronErrorCode = 0;
				$cronErrorMessage = '';
				// Try interpreting the cron command
				try {
					\TYPO3\CMS\Scheduler\CronCommand\NormalizeCommand::normalize($frequency);
					$this->submittedData['croncmd'] = $frequency;
				} catch (\Exception $e) {
					// Store the exception's result
					$cronErrorMessage = $e->getMessage();
					$cronErrorCode = $e->getCode();
					// Check if the frequency is a valid number
					// If yes, assume it is a frequency in seconds, and unset cron error code
					if (is_numeric($frequency)) {
						$this->submittedData['interval'] = (int)$frequency;
						unset($cronErrorCode);
					}
				}
				// If there's a cron error code, issue validation error message
				if (!empty($cronErrorCode)) {
					$this->addMessage(sprintf($this->getLanguageService()->getLL('msg.frequencyError'), $cronErrorMessage, $cronErrorCode), FlashMessage::ERROR);
					$result = FALSE;
				}
			}
		}
		// Validate additional input fields
		if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields'])) {
			/** @var $providerObject \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface */
			$providerObject = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$this->submittedData['class']]['additionalFields']);
			if ($providerObject instanceof \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface) {
				// The validate method will return TRUE if all went well, but that must not
				// override previous FALSE values => AND the returned value with the existing one
				$result &= $providerObject->validateAdditionalFields($this->submittedData, $this);
			}
		}
		return $result;
	}

	/**
	 * This method checks whether the given string can be considered a valid date or not
	 * Allowed values are anything that matches natural language (see PHP function strtotime())
	 * or TYPO3's date syntax: HH:ii yyyy-mm-dd
	 * If the string is a valid date, the corresponding timestamp is returned.
	 * Otherwise an exception is thrown
	 *
	 * @param string $string String to check
	 * @return int Unix timestamp
	 * @throws \InvalidArgumentException
	 * @deprecated since TYPO3 CMS 7, will be removed in CMS 8, as the unified datetime picker with a separate timestamp field is used.
	 */
	public function checkDate($string) {
		GeneralUtility::logDeprecatedFunction();
		// Try with strtotime
		$timestamp = strtotime($string);
		// That failed. Try TYPO3's standard date/time input format
		if ($timestamp === FALSE) {
			// Split time and date
			$dateParts = GeneralUtility::trimExplode(' ', $string, TRUE);
			// Proceed if there are indeed two parts
			// Extract each component of date and time
			if (count($dateParts) == 2) {
				list($time, $date) = $dateParts;
				list($hour, $minutes) = GeneralUtility::trimExplode(':', $time, TRUE);
				list($day, $month, $year) = GeneralUtility::trimExplode('-', $date, TRUE);
				// Get a timestamp from all these parts
				$timestamp = @mktime($hour, $minutes, 0, $month, $day, $year);
			}
			// If the timestamp is still FALSE, throw an exception
			if ($timestamp === FALSE) {
				throw new \InvalidArgumentException('"' . $string . '" seems not to be a correct date.', 1294587694);
			}
		}
		return $timestamp;
	}

	/*************************
	 *
	 * APPLICATION LOGIC UTILITIES
	 *
	 *************************/
	/**
	 * This method is used to add a message to the internal queue
	 *
	 * @param string $message The message itself
	 * @param int $severity Message level (according to FlashMessage class constants)
	 * @return void
	 */
	public function addMessage($message, $severity = FlashMessage::OK) {
		/** @var $flashMessage FlashMessage */
		$flashMessage = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessage::class, $message, '', $severity);
		/** @var $flashMessageService FlashMessageService */
		$flashMessageService = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessageService::class);
		/** @var $defaultFlashMessageQueue FlashMessageQueue */
		$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
		$defaultFlashMessageQueue->enqueue($flashMessage);
	}

	/**
	 * This method fetches a list of all classes that have been registered with the Scheduler
	 * For each item the following information is provided, as an associative array:
	 *
	 * ['extension']	=>	Key of the extension which provides the class
	 * ['filename']		=>	Path to the file containing the class
	 * ['title']		=>	String (possibly localized) containing a human-readable name for the class
	 * ['provider']		=>	Name of class that implements the interface for additional fields, if necessary
	 *
	 * The name of the class itself is used as the key of the list array
	 *
	 * @return array List of registered classes
	 */
	protected function getRegisteredClasses() {
		$list = array();
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'] as $class => $registrationInformation) {
				$title = isset($registrationInformation['title']) ? $this->getLanguageService()->sL($registrationInformation['title']) : '';
				$description = isset($registrationInformation['description']) ? $this->getLanguageService()->sL($registrationInformation['description']) : '';
				$list[$class] = array(
					'extension' => $registrationInformation['extension'],
					'title' => $title,
					'description' => $description,
					'provider' => isset($registrationInformation['additionalFields']) ? $registrationInformation['additionalFields'] : ''
				);
			}
		}
		return $list;
	}

	/**
	 * This method fetches list of all group that have been registered with the Scheduler
	 *
	 * @return array List of registered groups
	 */
	protected function getRegisteredTaskGroups() {
		$list = array();

		// Get all registered task groups
		$query = array(
			'SELECT' => '*',
			'FROM' => 'tx_scheduler_task_group',
			'WHERE' => '1=1'
				. BackendUtility::BEenableFields('tx_scheduler_task_group')
				. BackendUtility::deleteClause('tx_scheduler_task_group'),
			'ORDERBY' => 'sorting'
		);
		$res = $this->getDatabaseConnection()->exec_SELECT_queryArray($query);

		while (($groupRecord = $this->getDatabaseConnection()->sql_fetch_assoc($res)) !== FALSE) {
			$list[] = $groupRecord;
		}
		$this->getDatabaseConnection()->sql_free_result($res);

		return $list;
	}

	/*************************
	 *
	 * RENDERING UTILITIES
	 *
	 *************************/
	/**
	 * Gets the filled markers that are used in the HTML template.
	 *
	 * @return array The filled marker array
	 */
	protected function getTemplateMarkers() {
		return array(
			'CSH' => BackendUtility::wrapInHelp('_MOD_system_txschedulerM1', ''),
			'FUNC_MENU' => $this->getFunctionMenu(),
			'CONTENT' => $this->content,
			'TITLE' => $this->getLanguageService()->getLL('title')
		);
	}

	/**
	 * Gets the function menu selector for this backend module.
	 *
	 * @return string The HTML representation of the function menu selector
	 */
	protected function getFunctionMenu() {
		return BackendUtility::getFuncMenu(0, 'SET[function]', $this->MOD_SETTINGS['function'], $this->MOD_MENU['function']);
	}

	/**
	 * Gets the buttons that shall be rendered in the docHeader.
	 *
	 * @return array Available buttons for the docHeader
	 */
	protected function getDocHeaderButtons() {
		$buttons = array(
			'addtask' => '',
			'close' => '',
			'save' => '',
			'saveclose' => '',
			'savenew' => '',
			'delete' => '',
			'reload' => '',
			'shortcut' => $this->getShortcutButton()
		);
		if (empty($this->CMD) || $this->CMD === 'list' || $this->CMD === 'delete' || $this->CMD === 'toggleHidden') {
			$buttons['reload'] = '<a href="' . $GLOBALS['MCONF']['_'] . '" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.reload', TRUE) . '">' . IconUtility::getSpriteIcon('actions-system-refresh') . '</a>';
			if ($this->MOD_SETTINGS['function'] === 'scheduler' && count($this->getRegisteredClasses())) {
				$link = $GLOBALS['MCONF']['_'] . '&CMD=add';
				$image = IconUtility::getSpriteIcon('actions-document-new', array('alt' => $this->getLanguageService()->getLL('action.add')));
				$buttons['addtask'] = '<a href="' . htmlspecialchars($link) . '" ' . 'title="' . $this->getLanguageService()->getLL('action.add') . '">' . $image . '</a>';
			}
		}
		if ($this->CMD === 'add' || $this->CMD === 'edit') {
			$buttons['close'] = '<a href="#" onclick="document.location=\'' . $GLOBALS['MCONF']['_'] . '\'" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:cancel', TRUE) . '">' . IconUtility::getSpriteIcon('actions-document-close') . '</a>';
			$buttons['save'] = '<button style="padding: 0; margin: 0; cursor: pointer;" type="submit" name="CMD" value="save" class="c-inputButton" src="clear.gif" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:save', TRUE) . '" />' . IconUtility::getSpriteIcon('actions-document-save') . '</button>';
			$buttons['saveclose'] = '<button style="padding: 0; margin: 0; cursor: pointer;" type="submit" name="CMD" value="saveclose" class="c-inputButton" src="clear.gif" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:saveAndClose', TRUE) . '" />' . IconUtility::getSpriteIcon('actions-document-save-close') . '</button>';
			$buttons['savenew'] = '<button style="padding: 0; margin: 0; cursor: pointer;" type="submit" name="CMD" value="savenew" class="c-inputButton" src="clear.gif" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:saveAndCreateNewDoc', TRUE) . '" />' . IconUtility::getSpriteIcon('actions-document-save-new') . '</button>';
		}
		if ($this->CMD === 'edit') {
			$buttons['delete'] = '<button style="padding: 0; margin: 0; cursor: pointer;" type="submit" name="CMD" value="delete" class="c-inputButton" src="clear.gif" title="' . $this->getLanguageService()->sL('LLL:EXT:lang/locallang_common.xlf:delete', TRUE) . '" />' . IconUtility::getSpriteIcon('actions-edit-delete') . '</button>';
		}
		return $buttons;
	}

	/**
	 * Gets the button to set a new shortcut in the backend (if current user is allowed to).
	 *
	 * @return string HTML representation of the shortcut button
	 */
	protected function getShortcutButton() {
		$result = '';
		if ($this->getBackendUserAuthentication()->mayMakeShortcut()) {
			$result = $this->doc->makeShortcutIcon('', 'function', $this->moduleName);
		}
		return $result;
	}

	/**
	 * Returns the global BackendUserAuthentication object.
	 *
	 * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
	 */
	protected function getBackendUserAuthentication() {
		return $GLOBALS['BE_USER'];
	}

	/**
	 * Returns the database connection
	 *
	 * @return DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

}
