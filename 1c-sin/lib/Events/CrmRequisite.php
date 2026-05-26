<?php
namespace Rusgeocom\Rusgeocom\Events;


class CrmRequisite
{
    public static function onAfterAdd( $event): void
    {
        $data = "Событие create" . date('Y-m-d H:i:s') . print_r($event, true) . PHP_EOL;
        file_put_contents(__DIR__.'/log1.txt', $data, FILE_APPEND);

    }

    public static function onAfterUpdate($event): void
    {
        $data = "Событие create" . date('Y-m-d H:i:s') . print_r($event, true) . PHP_EOL;
        file_put_contents(__DIR__.'/log1.txt', $data, FILE_APPEND);
        die();
    }
}