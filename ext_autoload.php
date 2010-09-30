<?php
/*
 * Register necessary class names with autoloader
 *
 * $Id$
 */
$extensionPath = t3lib_extMgm::extPath('scheduler');
return array(
	'tx_scheduler' => $extensionPath . 'class.tx_scheduler.php',
	'tx_scheduler_croncmd' => $extensionPath . 'class.tx_scheduler_croncmd.php',
	'tx_scheduler_execution' => $extensionPath . 'class.tx_scheduler_execution.php',
	'tx_scheduler_failedexecutionexception' => $extensionPath . 'class.tx_scheduler_failedexecutionexception.php',
	'tx_scheduler_task' => $extensionPath . 'class.tx_scheduler_task.php',
	'tx_scheduler_sleeptask' => $extensionPath . 'examples/class.tx_scheduler_sleeptask.php',
	'tx_scheduler_sleeptask_additionalfieldprovider' => $extensionPath . 'examples/class.tx_scheduler_sleeptask_additionalfieldprovider.php',
	'tx_scheduler_testtask' => $extensionPath . 'examples/class.tx_scheduler_testtask.php',
	'tx_scheduler_testtask_additionalfieldprovider' => $extensionPath . 'examples/class.tx_scheduler_testtask_additionalfieldprovider.php',
	'tx_scheduler_additionalfieldprovider' => $extensionPath . 'interfaces/interface.tx_scheduler_additionalfieldprovider.php',
	'tx_scheduler_module' => $extensionPath . 'mod1/index.php',
	'tx_scheduler_croncmdtest' => $extensionPath . 'tests/tx_scheduler_croncmdTest.php',
);
?>