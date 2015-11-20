<?php
namespace TYPO3\CMS\Scheduler\Tests\Unit\CronCommand;

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

use TYPO3\CMS\Core\Tests\UnitTestCase;
use TYPO3\CMS\Scheduler\CronCommand\NormalizeCommand;
use TYPO3\CMS\Scheduler\Tests\Unit\CronCommand\AccessibleProxies\NormalizeCommandAccessibleProxy;

/**
 * Test case
 */
class NormalizeCommandTest extends UnitTestCase
{
    /**
     * @return array
     */
    public static function normalizeValidDataProvider()
    {
        return array(
            '@weekly' => array('@weekly', '0 0 * * 7'),
            ' @weekly ' => array(' @weekly ', '0 0 * * 7'),
            '* * * * *' => array('* * * * *', '* * * * *'),
            '30 4 1,15 * 5' => array('30 4 1,15 * 5', '30 4 1,15 * 5'),
            '5 0 * * *' => array('5 0 * * *', '5 0 * * *'),
            '15 14 1 * *' => array('15 14 1 * *', '15 14 1 * *'),
            '0 22 * * 1-5' => array('0 22 * * 1-5', '0 22 * * 1,2,3,4,5'),
            '23 0-23/2 * * *' => array('23 0-23/2 * * *', '23 0,2,4,6,8,10,12,14,16,18,20,22 * * *'),
            '5 4 * * sun' => array('5 4 * * sun', '5 4 * * 7'),
            '0-3/2,7 0,4 20-22, feb,mar-jun/2,7 1-3,sun' => array('0-3/2,7 0,4 20-22 feb,mar-jun/2,7 1-3,sun', '0,2,7 0,4 20,21,22 2,3,5,7 1,2,3,7'),
            '0-20/10 * * * *' => array('0-20/10 * * * *', '0,10,20 * * * *'),
            '* * 2 * *' => array('* * 2 * *', '* * 2 * *'),
            '* * 2,7 * *' => array('* * 2,7 * *', '* * 2,7 * *'),
            '* * 2-4,10 * *' => array('* * 2-4,10 * *', '* * 2,3,4,10 * *'),
            '* * */14 * *' => array('* * */14 * *', '* * 1,15,29 * *'),
            '* * 2,4-6/2,*/14 * *' => array('* * 2,4-6/2,*/14 * *', '* * 1,2,4,6,15,29 * *'),
            '* * * * 1' => array('* * * * 1', '* * * * 1'),
            '0 0 * * 0' => array('0 0 * * 0', '0 0 * * 7'),
            '0 0 * * 7' => array('0 0 * * 7', '0 0 * * 7'),
            '* * 1,2 * 1' => array('* * 1,2 * 1', '* * 1,2 * 1')
        );
    }

    /**
     * @test
     * @dataProvider normalizeValidDataProvider
     * @param string $expression Cron command to test
     * @param string $expected Expected result (normalized cron command syntax)
     */
    public function normalizeConvertsCronCommand($expression, $expected)
    {
        $result = NormalizeCommand::normalize($expression);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public static function validSpecialKeywordsDataProvider()
    {
        return array(
            '@yearly' => array('@yearly', '0 0 1 1 *'),
            '@annually' => array('@annually', '0 0 1 1 *'),
            '@monthly' => array('@monthly', '0 0 1 * *'),
            '@weekly' => array('@weekly', '0 0 * * 0'),
            '@daily' => array('@daily', '0 0 * * *'),
            '@midnight' => array('@midnight', '0 0 * * *'),
            '@hourly' => array('@hourly', '0 * * * *')
        );
    }

    /**
     * @test
     * @dataProvider validSpecialKeywordsDataProvider
     * @param string $keyword Cron command keyword
     * @param string $expectedCronCommand Expected result (normalized cron command syntax)
     */
    public function convertKeywordsToCronCommandConvertsValidKeywords($keyword, $expectedCronCommand)
    {
        $result = NormalizeCommandAccessibleProxy::convertKeywordsToCronCommand($keyword);
        $this->assertEquals($expectedCronCommand, $result);
    }

    /**
     * @test
     */
    public function convertKeywordsToCronCommandReturnsUnchangedCommandIfKeywordWasNotFound()
    {
        $invalidKeyword = 'foo';
        $result = NormalizeCommandAccessibleProxy::convertKeywordsToCronCommand($invalidKeyword);
        $this->assertEquals($invalidKeyword, $result);
    }

    /**
     * @return array
     */
    public function normalizeFieldsValidDataProvider()
    {
        return array(
            '1-2 * * * *' => array('1-2 * * * *', '1,2 * * * *'),
            '* 1-2 * * *' => array('* 1-2 * * *', '* 1,2 * * *'),
            '* * 1-2 * *' => array('* * 1-2 * *', '* * 1,2 * *'),
            '* * * 1-2 *' => array('* * * 1-2 *', '* * * 1,2 *'),
            '* * * * 1-2' => array('* * * * 1-2', '* * * * 1,2')
        );
    }

    /**
     * @test
     * @dataProvider normalizeFieldsValidDataProvider
     * @param string $expression Cron command to normalize
     * @param string $expected Expected result (normalized cron command syntax)
     */
    public function normalizeFieldsConvertsField($expression, $expected)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeFields($expression);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public static function normalizeMonthAndWeekdayFieldValidDataProvider()
    {
        return array(
            '* monthField' => array('*', true, '*'),
            'string 1 monthField' => array('1', true, '1'),
            'jan' => array('jan', true, '1'),
            'feb/2' => array('feb/2', true, '2'),
            'jan-feb/2' => array('jan-feb/2', true, '1'),
            '1-2 monthField' => array('1-2', true, '1,2'),
            '1-3/2,feb,may,6' => array('1-3/2,feb,may,6', true, '1,2,3,5,6'),
            '*/4' => array('*/4', true, '1,5,9'),
            '* !monthField' => array('*', false, '*'),
            'string 1, !monthField' => array('1', false, '1'),
            'fri' => array('fri', false, '5'),
            'sun' => array('sun', false, '7'),
            'string 0 for sunday' => array('0', false, '7'),
            '0,1' => array('0,1', false, '1,7'),
            '*/3' => array('*/3', false, '1,4,7'),
            'tue/2' => array('tue/2', false, '2'),
            '1-2 !monthField' => array('1-2', false, '1,2'),
            'tue-fri/2' => array('tue-fri/2', false, '2,4'),
            '1-3/2,tue,fri,6' => array('1-3/2,tue,fri,6', false, '1,2,3,5,6')
        );
    }

    /**
     * @test
     * @dataProvider normalizeMonthAndWeekdayFieldValidDataProvider
     * @param string $expression Cron command partial expression for month and weekday fields
     * @param bool $isMonthField Flag to designate month field or not
     * @param string $expected Expected result (normalized months or weekdays)
     */
    public function normalizeMonthAndWeekdayFieldReturnsNormalizedListForValidExpression($expression, $isMonthField, $expected)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonthAndWeekdayField($expression, $isMonthField);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array
     */
    public static function normalizeMonthAndWeekdayFieldInvalidDataProvider()
    {
        return array(
            'mon' => array('mon', true),
            '1-2/mon' => array('1-2/mon', true),
            '0,1' => array('0,1', true),
            'feb' => array('feb', false),
            '1-2/feb' => array('1-2/feb', false),
            '0-fri/2,7' => array('0-fri/2,7', false, '2,4,7')
        );
    }

    /**
     * @test
     * @dataProvider normalizeMonthAndWeekdayFieldInvalidDataProvider
     * @expectedException \InvalidArgumentException
     * @param string $expression Cron command partial expression for month and weekday fields (invalid)
     * @param bool $isMonthField Flag to designate month field or not
     */
    public function normalizeMonthAndWeekdayFieldThrowsExceptionForInvalidExpression($expression, $isMonthField)
    {
        NormalizeCommandAccessibleProxy::normalizeMonthAndWeekdayField($expression, $isMonthField);
    }

    /**
     * @return array
     */
    public static function normalizeIntegerFieldValidDataProvider()
    {
        return array(
            '*' => array('*', '*'),
            'string 2' => array('2', '2'),
            'integer 3' => array(3, '3'),
            'list of values' => array('1,2,3', '1,2,3'),
            'unsorted list of values' => array('3,1,5', '1,3,5'),
            'duplicate values' => array('0-2/2,2', '0,2'),
            'additional field between steps' => array('1-3/2,2', '1,2,3'),
            '2-4' => array('2-4', '2,3,4'),
            'simple step 4/4' => array('4/4', '4'),
            'step 2-7/5' => array('2-7/5', '2,7'),
            'steps 4-12/4' => array('4-12/4', '4,8,12'),
            '0-59/20' => array('0-59/20', '0,20,40'),
            '*/20' => array('*/20', '0,20,40')
        );
    }

    /**
     * @test
     * @dataProvider normalizeIntegerFieldValidDataProvider
     * @param string $expression Cron command partial integer expression
     * @param string $expected Expected result (normalized integer or integer list)
     */
    public function normalizeIntegerFieldReturnsNormalizedListForValidExpression($expression, $expected)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeIntegerField($expression);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array
     */
    public static function normalizeIntegerFieldInvalidDataProvider()
    {
        return array(
            'string foo' => array('foo', 0, 59),
            'empty string' => array('', 0, 59),
            '4-3' => array('4-3', 0, 59),
            '/2' => array('/2', 0, 59),
            '/' => array('/', 0, 59),
            'left bound too low' => array('2-4', 3, 4),
            'right bound too high' => array('2-4', 2, 3),
            'left and right bound' => array('2-5', 2, 4),
            'element in list is lower than allowed' => array('2,1,4', 2, 4),
            'element in list is higher than allowed' => array('2,5,4', 1, 4)
        );
    }

    /**
     * @test
     * @dataProvider normalizeIntegerFieldInvalidDataProvider
     * @expectedException \InvalidArgumentException
     * @param string $expression Cron command partial integer expression (invalid)
     * @param int $lowerBound Lower limit
     * @param int $upperBound Upper limit
     */
    public function normalizeIntegerFieldThrowsExceptionForInvalidExpressions($expression, $lowerBound, $upperBound)
    {
        NormalizeCommandAccessibleProxy::normalizeIntegerField($expression, $lowerBound, $upperBound);
    }

    /**
     * @test
     */
    public function splitFieldsReturnsIntegerArrayWithFieldsSplitByWhitespace()
    {
        $result = NormalizeCommandAccessibleProxy::splitFields('12,13 * 1-12/2,14 jan fri');
        $expectedResult = array(
            0 => '12,13',
            1 => '*',
            2 => '1-12/2,14',
            3 => 'jan',
            4 => 'fri'
        );
        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return array
     */
    public static function invalidCronCommandFieldsDataProvider()
    {
        return array(
            'empty string' => array(''),
            'foo' => array('foo'),
            'integer 4' => array(4),
            'four fields' => array('* * * *'),
            'six fields' => array('* * * * * *')
        );
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @dataProvider invalidCronCommandFieldsDataProvider
     * @param string $cronCommand Invalid cron command
     */
    public function splitFieldsThrowsExceptionIfCronCommandDoesNotContainFiveFields($cronCommand)
    {
        NormalizeCommandAccessibleProxy::splitFields($cronCommand);
    }

    /**
     * @return array
     */
    public static function validRangeDataProvider()
    {
        return array(
            'single value' => array('3', '3'),
            'integer 3' => array(3, '3'),
            '0-0' => array('0-0', '0'),
            '4-4' => array('4-4', '4'),
            '0-3' => array('0-3', '0,1,2,3'),
            '4-5' => array('4-5', '4,5')
        );
    }

    /**
     * @test
     * @dataProvider validRangeDataProvider
     * @param string $range Cron command range expression
     * @param string $expected Expected result (normalized range)
     */
    public function convertRangeToListOfValuesReturnsCorrectListForValidRanges($range, $expected)
    {
        $result = NormalizeCommandAccessibleProxy::convertRangeToListOfValues($range);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array
     */
    public static function invalidRangeDataProvider()
    {
        return array(
            'empty string' => array(''),
            'string' => array('foo'),
            'single dash' => array('-'),
            'left part is string' => array('foo-5'),
            'right part is string' => array('5-foo'),
            'range of strings' => array('foo-bar'),
            'string five minus' => array('5-'),
            'string minus five' => array('-5'),
            'more than one dash' => array('2-3-4'),
            'left part bigger than right part' => array('6-3')
        );
    }

    /**
     * @test
     * @dataProvider invalidRangeDataProvider
     * @expectedException \InvalidArgumentException
     * @param string $range Cron command range expression (invalid)
     */
    public function convertRangeToListOfValuesThrowsExceptionForInvalidRanges($range)
    {
        NormalizeCommandAccessibleProxy::convertRangeToListOfValues($range);
    }

    /**
     * @return array
     */
    public static function validStepsDataProvider()
    {
        return array(
            '2/2' => array('2/2', '2'),
            '2,3,4/2' => array('2,3,4/2', '2,4'),
            '1,2,3,4,5,6,7/3' => array('1,2,3,4,5,6,7/3', '1,4,7'),
            '0,1,2,3,4,5,6/3' => array('0,1,2,3,4,5,6/3', '0,3,6')
        );
    }

    /**
     * @test
     * @dataProvider validStepsDataProvider
     * @param string $stepExpression Cron command step expression
     * @param string $expected Expected result (normalized range)
     */
    public function reduceListOfValuesByStepValueReturnsCorrectListOfValues($stepExpression, $expected)
    {
        $result = NormalizeCommandAccessibleProxy::reduceListOfValuesByStepValue($stepExpression);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array
     */
    public static function invalidStepsDataProvider()
    {
        return array(
            'empty string' => array(''),
            'slash only' => array('/'),
            'left part empty' => array('/2'),
            'right part empty' => array('2/'),
            'multiples slashes' => array('1/2/3'),
            '2-2' => array('2-2'),
            '2.3/2' => array('2.3/2'),
            '2,3,4/2.3' => array('2,3,4/2.3'),
            '2,3,4/2,3' => array('2,3,4/2,3')
        );
    }

    /**
     * @test
     * @dataProvider invalidStepsDataProvider
     * @expectedException \InvalidArgumentException
     * @param string $stepExpression Cron command step expression (invalid)
     */
    public function reduceListOfValuesByStepValueThrowsExceptionForInvalidStepExpressions($stepExpression)
    {
        NormalizeCommandAccessibleProxy::reduceListOfValuesByStepValue($stepExpression);
    }

    /**
     * @test
     */
    public function normalizeMonthAndWeekdayNormalizesAMonth()
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonthAndWeekday('feb', true);
        $this->assertSame('2', $result);
    }

    /**
     * @test
     */
    public function normalizeMonthAndWeekdayNormalizesAWeekday()
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonthAndWeekday('fri', false);
        $this->assertSame('5', $result);
    }

    /**
     * @test
     */
    public function normalizeMonthAndWeekdayLeavesValueUnchanged()
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonthAndWeekday('2');
        $this->assertSame('2', $result);
    }

    /**
     * @return array
     */
    public static function validMonthNamesDataProvider()
    {
        return array(
            'jan' => array('jan', 1),
            'feb' => array('feb', 2),
            'MaR' => array('MaR', 3),
            'aPr' => array('aPr', 4),
            'MAY' => array('MAY', 5),
            'jun' => array('jun', 6),
            'jul' => array('jul', 7),
            'aug' => array('aug', 8),
            'sep' => array('sep', 9),
            'oct' => array('oct', 10),
            'nov' => array('nov', 11),
            'dec' => array('dec', 12),
            'string 7' => array('7', 7),
            'integer 7' => array(7, 7),
            'string 07' => array('07', 7),
            'integer 07' => array(7, 7)
        );
    }

    /**
     * @test
     * @dataProvider validMonthNamesDataProvider
     * @param string $monthName Month name
     * @param int $expectedInteger Number of the month
     */
    public function normalizeMonthConvertsName($monthName, $expectedInteger)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonth($monthName);
        $this->assertEquals($expectedInteger, $result);
    }

    /**
     * @test
     * @dataProvider validMonthNamesDataProvider
     * @param string $monthName Month name
     * @param int $expectedInteger Number of the month (not used)
     */
    public function normalizeMonthReturnsInteger($monthName, $expectedInteger)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeMonth($monthName);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $result);
    }

    /**
     * @return array
     */
    public static function invalidMonthNamesDataProvider()
    {
        return array(
            'sep-' => array('sep-'),
            '-September-' => array('-September-'),
            ',sep' => array(',sep'),
            ',September,' => array(',September,'),
            'sep/' => array('sep/'),
            '/sep' => array('/sep'),
            '/September/' => array('/September/'),
            'foo' => array('foo'),
            'Tuesday' => array('Tuesday'),
            'Tue' => array('Tue'),
            'string 0' => array('0'),
            'integer 0' => array(0),
            'string seven' => array('seven'),
            'string 13' => array('13'),
            'integer 13' => array(13),
            'integer 100' => array(100),
            'integer 2010' => array(2010),
            'string minus 7' => array('-7'),
            'negative integer 7' => array(-7)
        );
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @dataProvider invalidMonthNamesDataProvider
     * @param string $invalidMonthName Month name (invalid)
     */
    public function normalizeMonthThrowsExceptionForInvalidMonthRepresentation($invalidMonthName)
    {
        NormalizeCommandAccessibleProxy::normalizeMonth($invalidMonthName);
    }

    /**
     * @return array
     */
    public static function validWeekdayDataProvider()
    {
        return array(
            'string 1' => array('1', 1),
            'string 2' => array('2', 2),
            'string 02' => array('02', 2),
            'integer 02' => array(2, 2),
            'string 3' => array('3', 3),
            'string 4' => array('4', 4),
            'string 5' => array('5', 5),
            'integer 5' => array(5, 5),
            'string 6' => array('6', 6),
            'string 7' => array('7', 7),
            'string 0' => array('0', 7),
            'integer 0' => array(0, 7),
            'mon' => array('mon', 1),
            'monday' => array('monday', 1),
            'tue' => array('tue', 2),
            'tuesday' => array('tuesday', 2),
            'WED' => array('WED', 3),
            'WEDnesday' => array('WEDnesday', 3),
            'tHu' => array('tHu', 4),
            'Thursday' => array('Thursday', 4),
            'fri' => array('fri', 5),
            'friday' => array('friday', 5),
            'sat' => array('sat', 6),
            'saturday' => array('saturday', 6),
            'sun' => array('sun', 7),
            'sunday' => array('sunday', 7)
        );
    }

    /**
     * @test
     * @dataProvider validWeekdayDataProvider
     * @param string $weekday Weekday expression
     * @param int $expectedInteger Number of weekday
     */
    public function normalizeWeekdayConvertsName($weekday, $expectedInteger)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeWeekday($weekday);
        $this->assertEquals($expectedInteger, $result);
    }

    /**
     * @test
     * @dataProvider validWeekdayDataProvider
     * @param string $weekday Weekday expression
     * @param int $expectedInteger Number of weekday (not used)
     */
    public function normalizeWeekdayReturnsInteger($weekday, $expectedInteger)
    {
        $result = NormalizeCommandAccessibleProxy::normalizeWeekday($weekday);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $result);
    }

    /**
     * @return array
     */
    public static function invalidWeekdayDataProvider()
    {
        return array(
            '-fri' => array('-fri'),
            'fri-' => array('fri-'),
            '-friday-' => array('-friday-'),
            '/fri' => array('/fri'),
            'fri/' => array('fri/'),
            '/friday/' => array('/friday/'),
            ',fri' => array(',fri'),
            ',friday,' => array(',friday,'),
            'string minus 1' => array('-1'),
            'integer -1' => array(-1),
            'string seven' => array('seven'),
            'string 8' => array('8'),
            'string 29' => array('29'),
            'string 2010' => array('2010'),
            'Jan' => array('Jan'),
            'January' => array('January'),
            'MARCH' => array('MARCH')
        );
    }

    /**
     * @test
     * @dataProvider invalidWeekdayDataProvider
     * @expectedException \InvalidArgumentException
     * @param string $weekday Weekday expression (invalid)
     */
    public function normalizeWeekdayThrowsExceptionForInvalidWeekdayRepresentation($weekday)
    {
        NormalizeCommandAccessibleProxy::normalizeWeekday($weekday);
    }
}
