# Edu-connect Realtime Event Contract

This document defines the v2 backend realtime events used by the Edu-connect mobile app and admin web panel. The backend emits private Laravel broadcast events through `App\Events\V2\MobileRealtimeEvent` and centralizes domain emission in `App\Services\Realtime\MobileRealtimeBroadcaster`.

## Transport

- Driver: Laravel broadcasting, configured by `BROADCAST_CONNECTION`.
- Production-compatible protocol: Pusher/Reverb-compatible private channels.
- Mobile auth endpoints:
  - `GET /api/mobile/v2/realtime/config`
  - `POST /api/mobile/v2/realtime/auth`
  - `POST /api/mobile/v2/realtime/heartbeat`
- Admin web auth endpoint:
  - `POST /admin/conversations/realtime/auth`
- All event broadcasts are private-channel broadcasts.
- Events are emitted after database commit when the triggering action runs inside a transaction.

## Event Envelope

Every backend event uses this shape:

```json
{
  "event": "mobile.notification.created",
  "data": {},
  "occurred_at": "2026-08-08T12:00:00+01:00"
}
```

Clients should use the top-level `event` value for routing and treat `data` as the domain payload.

## Channel Registry

| Channel | Purpose |
| --- | --- |
| `private-parent.{parentId}` | Parent account scoped updates. |
| `private-parent.{parentId}.notifications` | Parent notification and unread-count updates. |
| `private-parent.{parentId}.children` | Parent child-link and children-list refreshes. Reserved for config/auth exposure. |
| `private-parent.{parentId}.student.{studentId}` | Updates for one linked student. |
| `private-school.{schoolId}.parent.{parentId}` | Parent scoped school updates for parents with children in multiple schools. |
| `private-conversation.{threadId}` | Direct, class-group, or school-channel conversation messages visible to the subscriber. |
| `private-school.{schoolId}.class.{classId}.parents` | System-managed class group feed for parents of students in the class. |
| `private-school.{schoolId}.channels` | System-managed school channel/forum feed for parents with children in the school. |
| `private-school.{schoolId}.admins.conversations` | Admin web panel feed for conversation messages and thread changes inside one school. |

The backend currently avoids broadcasting private student events to broad school or class channels. Attendance updates are parent/student scoped only.

## Events

| Event | Trigger | Channels | Client action |
| --- | --- | --- | --- |
| `mobile.notification.created` | A `MobileNotification` row is created. | `private-parent.{parentId}`, `private-parent.{parentId}.notifications`, optional `private-parent.{parentId}.student.{studentId}`, optional `private-school.{schoolId}.parent.{parentId}`. | Insert or refetch notification, update badge count. |
| `mobile.notifications.changed` | A notification is marked read or all matching notifications are marked read. | Parent notification channels, plus optional parent-school channel. | Refresh notification list and unread count. |
| `mobile.message.published` | A published official mobile message receives a parent recipient row. | Parent channels, optional student channel, parent-school channel. | Refetch official inbox or load the message by ID. |
| `mobile.message.read` | Parent marks an official mobile message as read. | Parent channels and parent-school channel. | Update local read state. |
| `mobile.child.linked` | Parent links a child code and automatic class group/school channel threads are ensured. | Parent channels, `private-parent.{parentId}.children`, student channel, parent-school channel. | Refresh children, realtime config, conversations, and school/class memberships. |
| `mobile.attendance.recorded` | A linked active student's attendance event is captured. | Parent channels, student channel, parent-school channel. | Refetch child attendance and insert/update notification state. |
| `mobile.conversation.message.created` | A parent or administrator posts a conversation message. | `private-conversation.{threadId}` and `private-school.{schoolId}.admins.conversations`. Class groups also emit to `private-school.{schoolId}.class.{classId}.parents`; school channels also emit to `private-school.{schoolId}.channels`. | Append message to the open thread, update conversation list ordering/unread state. |
| `mobile.conversation.thread.changed` | Administrator changes conversation thread status. | `private-conversation.{threadId}` and `private-school.{schoolId}.admins.conversations`. | Update thread status and available reply actions. |

## Payload Notes

### `mobile.notification.created`

Includes `notification_id`, `parent_account_id`, `tenant_id`, `school_id`, `type`, `priority`, `read_at`, `created_at`, and notification `data`.

### `mobile.message.published`

Includes `mobile_message_id`, `recipient_id`, `parent_account_id`, `tenant_id`, `school_id`, optional `student_id`, `category`, `priority`, and `published_at`. The message body is intentionally not included; clients should fetch message details through the inbox API.

### `mobile.child.linked`

Includes `parent_account_id`, `link_id`, `student_id`, `school_id`, `class_id`, and `threads`. Each thread item contains `id`, `type`, `school_id`, `class_id`, `student_id`, and `realtime_channel`.

### `mobile.attendance.recorded`

Includes `attendance_event_id`, `event_key`, `event_type`, `event_time`, `parent_account_id`, `tenant_id`, `school_id`, `student_id`, and `device_id`.

### `mobile.conversation.message.created`

Includes a `thread` object and a `message` object. Direct messages only emit on the private conversation channel. System-managed class groups and school channels also emit to their aggregate parent feed channels.

## Client Subscription Rules

### Mobile

1. On mobile sign-in, call `/api/mobile/v2/realtime/config`, then authorize and subscribe only to the returned channels.
2. After `mobile.child.linked`, refetch realtime config because the parent may now have new school, class group, student, and conversation channels.
3. Parents with children in multiple schools must keep school-specific state keyed by `school_id`.
4. Push notifications remain the delivery fallback when the app is offline; realtime events are the foreground/live state sync layer.
5. Clients should deduplicate by stable IDs such as `notification_id`, `mobile_message_id`, `attendance_event_id`, `thread.id`, and `message.id`.

### Admin Web

1. The admin conversation panel is available at `/admin/conversations`.
2. The page receives initial `private-school.{schoolId}.admins.conversations` and visible `private-conversation.{threadId}` channels from the web controller.
3. The Pusher/Reverb client authorizes private admin subscriptions through `/admin/conversations/realtime/auth`.
4. School admins can authorize only school conversation channels mapped to their school scope. Super admins can authorize all v2 school conversation channels.
5. The admin client deduplicates messages by `message.id` because the same event can arrive through both the school-admin channel and the thread channel.

## Security Rules

- All channels are private and authorized server-side.
- Attendance events are never broadcast to `private-school.{schoolId}.parents` or class-wide parent channels.
- Official message publish events do not include message body content.
- Child-link events are emitted only to channels derived from the linked parent account.
- Conversation message bodies are emitted only to conversation/group/channel subscriptions that the parent is authorized to hold.
- Admin school conversation channels are authorized only for scoped school admins and super admins.
- No access tokens, provider push tokens, webhook secrets, or connector credentials are included in realtime payloads.
