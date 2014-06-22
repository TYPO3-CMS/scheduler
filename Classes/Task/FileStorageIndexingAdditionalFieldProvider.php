<?php
namespace TYPO3\CMS\Scheduler\Task;

/**
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

/**
 * Additional BE fields for tasks which indexes files in a storage
 *
 */
class FileStorageIndexingAdditionalFieldProvider implements \TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface {

	/**
	 * Add additional fields
	 *
	 * @param array $taskInfo Reference to the array containing the info used in the add/edit form
	 * @param object $task When editing, reference to the current task object. Null when adding.
	 * @param \TYPO3\CMS\Scheduler\Controller\SchedulerModuleController $parentObject Reference to the calling object (Scheduler's BE module)
	 * @return array Array containing all the information pertaining to the additional fields
	 * @throws \InvalidArgumentException
	 */
	public function getAdditionalFields(array &$taskInfo, $task, \TYPO3\CMS\Scheduler\Controller\SchedulerModuleController $parentObject) {
		if ($task !== NULL && !$task instanceof FileStorageIndexingTask) {
			throw new \InvalidArgumentException('Task not of type FileStorageExtractionTask', 1384275696);
		}
		$additionalFields['scheduler_fileStorageIndexing_storage'] = $this->getAllStoragesField($task);
		return $additionalFields;
	}

	/**
	 * Add a select field of available storages.
	 *
	 * @param FileStorageIndexingTask $task When editing, reference to the current task object. NULL when adding.
	 * @return array Array containing all the information pertaining to the additional fields
	 */
	protected function getAllStoragesField(FileStorageIndexingTask $task = NULL) {
		/** @var \TYPO3\CMS\Core\Resource\ResourceStorage[] $storages */
		$storages = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Core\Resource\StorageRepository')->findAll();
		$options = array();
		foreach ($storages as $storage) {
			if ($task != NULL && $task->storageUid === $storage->getUid()) {
				$options[] = '<option value="' . $storage->getUid() . '" selected="selected">' . $storage->getName() . '</option>';
			} else {
				$options[] = '<option value="' . $storage->getUid() . '">' . $storage->getName() . '</option>';
			}
		}

		$fieldName = 'tx_scheduler[scheduler_fileStorageIndexing_storage]';
		$fieldId = 'scheduler_fileStorageIndexing_storage';
		$fieldHtml = '<select name="' . $fieldName . '" id="' . $fieldId . '">' . implode("\n", $options) . '</select>';

		$fieldConfiguration = array(
			'code' => $fieldHtml,
			'label' => 'LLL:EXT:scheduler/mod1/locallang.xlf:label.fileStorageIndexing.storage',
			'cshKey' => '_MOD_system_txschedulerM1',
			'cshLabel' => $fieldId
		);
		return $fieldConfiguration;
	}

	/**
	 * Validate additional fields
	 *
	 * @param array $submittedData Reference to the array containing the data submitted by the user
	 * @param \TYPO3\CMS\Scheduler\Controller\SchedulerModuleController $parentObject Reference to the calling object (Scheduler's BE module)
	 * @return boolean True if validation was ok (or selected class is not relevant), false otherwise
	 */
	public function validateAdditionalFields(array &$submittedData, \TYPO3\CMS\Scheduler\Controller\SchedulerModuleController $parentObject) {
		$value = $submittedData['scheduler_fileStorageIndexing_storage'];
		if (!\TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($value)) {
			return FALSE;
		} elseif(\TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->getStorageObject($submittedData['scheduler_fileStorageIndexing_storage']) !== NULL) {
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * Save additional field in task
	 *
	 * @param array $submittedData Contains data submitted by the user
	 * @param \TYPO3\CMS\Scheduler\Task\AbstractTask $task Reference to the current task object
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	public function saveAdditionalFields(array $submittedData, \TYPO3\CMS\Scheduler\Task\AbstractTask $task) {
		if (!$task instanceof FileStorageIndexingTask) {
			throw new \InvalidArgumentException('Task not of type FileStorageExtractionTask', 1384275697);
		}
		$task->storageUid = (int)$submittedData['scheduler_fileStorageIndexing_storage'];
	}

}
