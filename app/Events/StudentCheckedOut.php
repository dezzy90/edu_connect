<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Student;
use App\Models\StudentLog;

class StudentCheckedOut implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Student $student;
    public StudentLog $log;

    /**
     * Create a new event instance.
     */
    public function __construct(Student $student, StudentLog $log)
    {
        $this->student = $student;
        $this->log = $log;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('school.' . $this->student->school_id),
            new PrivateChannel('class.' . $this->student->class_id),
            new PrivateChannel('student.' . $this->student->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'student.checked-out';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'student' => [
                'id' => $this->student->id,
                'student_number' => $this->student->student_number,
                'full_name' => $this->student->full_name,
                'class_id' => $this->student->class_id,
                'photo' => $this->student->photo,
            ],
            'log' => [
                'id' => $this->log->id,
                'event_type' => $this->log->event_type,
                'created_at' => $this->log->created_at->toISOString(),
                'formatted_time' => $this->log->formatted_time,
                'device_id' => $this->log->device_id,
                'confidence_score' => $this->log->confidence_score,
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}
