<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\ConversationMessageAttachment;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationAttachmentController extends Controller
{
    public function show(
        Request $request,
        ConversationMessageAttachment $attachment,
        ConversationService $conversations
    ): StreamedResponse {
        $attachment->loadMissing('message.thread');
        abort_unless($attachment->message?->thread, 404);
        $conversations->ensureParentCanAccessThread($request->user(), $attachment->message->thread);

        return $this->fileResponse($request, $attachment);
    }

    public function transfer(Request $request, ConversationMessageAttachment $attachment): StreamedResponse
    {
        return $this->fileResponse($request, $attachment);
    }

    private function fileResponse(Request $request, ConversationMessageAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return $request->boolean('download')
            ? Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, $headers)
            : Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, $headers);
    }
}
