<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Src\Service\EmployeeService;

class EmployeeServiceTest extends TestCase
{
    public function testDerivesOurEmployees(): void
    {
        $input = [
            ['username' => 'client1', 'nickname' => 'Client Person'],
            ['username' => 'vdevt', 'nickname' => 'Vlad'],
            ['username' => 'other', 'nickname' => 'Developer AIO'],
        ];

        $expected = [
            ['username' => 'vdevt', 'nickname' => 'Vlad'],
            ['username' => 'other', 'nickname' => 'Developer AIO'],
        ];

        $this->assertSame($expected, EmployeeService::deriveOurEmployees($input));
    }

    public function testHandlesCaseInsAndLeadingAt(): void
    {
        $input = [
            ['username' => '@nik_sre', 'nickname' => 'Nick'],
            ['username' => 'user', 'nickname' => 'lover of aio'],
            ['username' => 'random', 'nickname' => 'Client'],
        ];

        $expected = [
            ['username' => '@nik_sre', 'nickname' => 'Nick'],
            ['username' => 'user', 'nickname' => 'lover of aio'],
        ];

        $this->assertSame($expected, EmployeeService::deriveOurEmployees($input));
    }
}
