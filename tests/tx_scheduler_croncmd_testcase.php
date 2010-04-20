<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Christian Kuhn <lolli@schwarzbu.ch>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Testcase for class "tx_scheduler_CronCmd"
 *
 * @package TYPO3
 * @subpackage tx_scheduler
 *
 * @author Christian Kuhn <lolli@schwarzbu.ch>
 */
class tx_scheduler_croncmd_testcase extends tx_phpunit_testcase {
	/**
	 * @const	integer	timestamp of 1.1.2010 0:00 (Friday)
	 */
	const TIMESTAMP = 1262300400;

	/**
	 * Tests whether dayList is correctly calculated for a single day of month
	 *
	 * @test
	 */
	public function isDayListCorrectForOneDayOfMonth() {
		$cronCmdInstance = t3lib_div::makeInstance('tx_scheduler_cronCmd', '* * 2 * *');
		$expectedResult = array(
			'0' => 2,
		);
		$actualResult = $cronCmdInstance->valid_values;
		$this->assertEquals($expectedResult, $actualResult[2]);
	}

	/**
	 * Tests whether dayList is correctly calculated for a comma separated list of month days
	 *
	 * @test
	 */
	public function isDayListCorrectForListOfDayOfMonth() {
		$cronCmdInstance = t3lib_div::makeInstance('tx_scheduler_cronCmd', '* * 2,7 * *');
		$expectedResult = array(
			'0' => 2,
			'1' => 7,
		);
		$actualResult = $cronCmdInstance->valid_values;
		$this->assertEquals($expectedResult, $actualResult[2]);
	}

	/**
	 * Tests whether dayList is correctly calculated for a range of month days
	 *
	 * @test
	 */
	public function isDayListCorrectForRangeOfDayOfMonth() {
		$cronCmdInstance = t3lib_div::makeInstance('tx_scheduler_cronCmd', '* * 2-4,10 * *');
		$expectedResult = array(
			'0' => 2,
			'1' => 3,
			'2' => 4,
			'3' => 10,
		);
		$actualResult = $cronCmdInstance->valid_values;
		$this->assertEquals($expectedResult, $actualResult[2]);
	}

	/**
	 * Tests whether dayList is correctly calculated for a single day of week
	 *
	 * @test
	 */
	public function isDayListCorrectForOneDayOfWeek() {
		$cronCmdInstance = t3lib_div::makeInstance('tx_scheduler_cronCmd', '* * * * 1', self::TIMESTAMP);
		$expectedResult = array(
			'0' => 4,
			'1' => 11,
			'2' => 18,
			'3' => 25,
		);
		$actualResult = $cronCmdInstance->valid_values;
		$this->assertEquals($expectedResult, $actualResult[2]);
	}

	/**
	 * Tests whether dayList is correctly calculated for a combination of day of month and day of weeks
	 *
	 * @test
	 */
	public function isDayListCorrectForCombinationOfDayOfMonthAndDayOfWeek() {
		$cronCmdInstance = t3lib_div::makeInstance('tx_scheduler_cronCmd', '* * 1,2 * 1', self::TIMESTAMP);
		$expectedResult = array(
			'0' => 1,
			'1' => 2,
			'2' => 4,
			'3' => 11,
			'4' => 18,
			'5' => 25,
		);
		$actualResult = $cronCmdInstance->valid_values;
		$this->assertEquals($expectedResult, $actualResult[2]);
	}
}
?>