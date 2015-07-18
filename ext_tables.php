<?php
defined('TYPO3_MODE') or die();

if (TYPO3_MODE === 'BE') {
	// Add module
	\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
		'system',
		'txschedulerM1',
		'',
		'EXT:scheduler/Modules/Scheduler/',
		array(
			'script' => '_DISPATCH',
			'access' => 'admin',
			'name' => 'system_txschedulerM1',
			'labels' => array(
				'tabs_images' => array(
					'tab' => 'EXT:scheduler/Resources/Public/Icons/module-scheduler.svg',
				),
				'll_ref' => 'LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf',
			),
		)
	);

	// Add context sensitive help (csh) to the backend module
	\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addLLrefForTCAdescr(
		'_MOD_system_txschedulerM1',
		'EXT:scheduler/Resources/Private/Language/locallang_csh_scheduler.xlf'
	);
}

// Register specific icon for run task button
\TYPO3\CMS\Backend\Sprite\SpriteManager::addSingleIcons(
	array(
		'run-task' => \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extRelPath('scheduler') . 'Resources/Public/Images/Icons/RunTask.png'
	),
	'scheduler'
);
