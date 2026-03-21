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

namespace TYPO3\CMS\Scheduler\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SchedulerTaskRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'scheduler',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/FindNextExecutableTask.csv');
        // All fixture tasks have nextexecution in the past relative to this value
        $GLOBALS['EXEC_TIME'] = 9999999999;
    }

    #[Test]
    public function findNextExecutableTaskReturnsByPriorityDescFirst(): void
    {
        $subject = $this->get(SchedulerTaskRepository::class);

        $task = $subject->findNextExecutableTask();

        // Task 3 has priority=150 (High) and should win over tasks 1 and 2
        self::assertNotNull($task);
        self::assertSame(3, $task->getTaskUid());
    }

    #[Test]
    public function findNextExecutableTaskUsesNextExecutionAsTiebreakerForEqualPriority(): void
    {
        $subject = $this->get(SchedulerTaskRepository::class);

        // Disable task 3 (High priority) so only Regular-priority tasks 2 and 4 compete
        $this->getConnectionPool()
            ->getConnectionForTable('tx_scheduler_task')
            ->update('tx_scheduler_task', ['disable' => 1], ['uid' => 3]);

        $task = $subject->findNextExecutableTask();

        // Task 4 has priority=100 and nextexecution=1500; task 2 has priority=100 and nextexecution=2000
        // Task 4 is more overdue and should be picked first
        self::assertNotNull($task);
        self::assertSame(4, $task->getTaskUid());
    }
}
