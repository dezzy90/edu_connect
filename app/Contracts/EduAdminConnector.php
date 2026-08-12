<?php

namespace App\Contracts;

interface EduAdminConnector
{
    public function bootstrap(): array;

    public function resource(string $resource, ?string $cursor = null, array $filters = []): array;

    public function pushAttendanceEvents(array $events): array;

    public function pushConversationMessage(array $message): array;
}
