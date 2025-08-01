<?php
declare(strict_types=1);

namespace Src\Service;

class EmployeeService
{
    /**
     * Manual list of our employees that don't have "AIO" in their nickname.
     * Usernames should be stored without leading '@' and in lowercase.
     */
    private const MANUAL_EMPLOYEES = [
        'vdevt',
        'meowmeat',
        'nik_sre',
    ];

    /**
     * Derive our employees from the list of client employees.
     *
     * Each employee should be represented as an associative array containing
     * at least the keys 'username' and 'nickname'. The check is done
     * case-insensitively.
     *
     * @param array<int,array<string,string>> $clientEmployees
     * @return array<int,array<string,string>> our employees extracted from the input
     */
    public static function deriveOurEmployees(array $clientEmployees): array
    {
        $ourEmployees = [];
        foreach ($clientEmployees as $employee) {
            $nickname = $employee['nickname'] ?? '';
            $username = $employee['username'] ?? '';
            $username = ltrim($username, '@');

            if (stripos($nickname, 'AIO') !== false) {
                $ourEmployees[] = $employee;
                continue;
            }

            if (in_array(strtolower($username), self::MANUAL_EMPLOYEES, true)) {
                $ourEmployees[] = $employee;
            }
        }

        return $ourEmployees;
    }
}
