<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\UserTable;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class UserService
{
    use ExchangeHelperTrait;
    public static function getUsers(): array
    {
        $cursor = UserTable::getList([
            'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'XML_ID'],
            'filter' => ['ACTIVE' => 'Y', '!=UF_DEPARTMENT' => false],
            'order'  => ['ID' => 'ASC']
        ]);

        return array_map(static function($user) {
            $secondName = (string)$user['SECOND_NAME'];
            $personalNumber = '';

            if (preg_match('/^(.*?)\s*-\s*(.*)$/', $secondName, $matches)) {
                $secondName = trim($matches[1]);
                $personalNumber = trim($matches[2]);
            }

            $cleanUserForFio = [
                'NAME'        => $user['NAME'],
                'LAST_NAME'   => $user['LAST_NAME'],
                'SECOND_NAME' => $secondName
            ];

            return [
                'b24_id'          => (int)$user['ID'],
                'fio'             => \CUser::FormatName('#LAST_NAME# #NAME# #SECOND_NAME#', $cleanUserForFio, false, false),
                'personal_number' => $personalNumber,
                'guid'            => $user['XML_ID'],
            ];
        }, $cursor->fetchAll());
    }

    public static function syncUsers(array $params): array
    {
        $results = [];
        $userObj = new \CUser;

        foreach ($params as $item) {
            $id = (int)($item['b24_id']);
            $guid = (string)($item['guid']);

            if ($id <= 0 || empty($guid)){
                $results[] = self::errorResult('PARAMS ERROR', 'Передан некорректный параметр', $guid);
            }
            else if ($userObj->Update($id, ['XML_ID' => $guid])) {
                $results[] = self::successResult($id, $guid, 'SYNC USER OK');
            } else {
                $results[] = self::errorResult('SYNC USER ERROR', $userObj->LAST_ERROR, $guid);
            }
        }

        return $results;
    }
}