import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/AdminLayout';
import { Head } from '@inertiajs/react';
import {
    Archive,
    CheckCheck,
    Circle,
    Inbox,
    Loader2,
    Lock,
    Megaphone,
    MessageSquareText,
    RefreshCw,
    School,
    Search,
    Send,
    UsersRound,
    Wifi,
    WifiOff,
} from 'lucide-react';
import Pusher from 'pusher-js';
import {
    FormEvent,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
    school?: {
        id: number;
        name: string;
    } | null;
}

type ThreadType = 'direct' | 'class_group' | 'school_channel';
type ThreadStatus = 'open' | 'closed' | 'archived';
type LiveStatus =
    | 'disabled'
    | 'connecting'
    | 'connected'
    | 'unavailable'
    | 'error';

interface ConversationMessage {
    id: number;
    thread_id: number;
    sender_type: 'parent' | 'admin' | string;
    sender_id?: number | null;
    sender_display_name: string;
    message_type: string;
    body: string;
    status: string;
    sent_at?: string | null;
    own_message: boolean;
}

interface ConversationThread {
    id: number;
    tenant_id: number;
    school_id?: number | null;
    class_id?: number | null;
    student_id?: number | null;
    type: ThreadType;
    title: string;
    status: ThreadStatus;
    metadata: Record<string, unknown>;
    can_post: boolean;
    unread_count: number;
    participants_count?: number | null;
    messages_count?: number | null;
    last_message_at?: string | null;
    realtime_channel: string;
    school?: {
        id: number;
        name: string;
        code?: string | null;
    } | null;
    class?: {
        id: number;
        name: string;
        full_name?: string | null;
    } | null;
    student?: {
        id: number;
        student_number: string;
        full_name: string;
    } | null;
    last_message?: ConversationMessage | null;
}

interface RealtimeConfig {
    enabled: boolean;
    driver?: string | null;
    authEndpoint: string;
    channels: string[];
    connection: {
        key?: string | null;
        host?: string | null;
        port?: number | string | null;
        scheme?: string | null;
    };
}

interface Props {
    admin: AdminUser;
    isSuper: boolean;
    csrfToken: string;
    threads: ConversationThread[];
    selectedThread?: ConversationThread | null;
    messages: ConversationMessage[];
    realtime: RealtimeConfig;
}

interface ApiResponse<T> {
    status: string;
    data: T;
}

interface MessageRealtimeEnvelope {
    event: 'mobile.conversation.message.created';
    data: {
        thread: Partial<ConversationThread> & {
            id: number;
            type: ThreadType;
            title: string;
            status: ThreadStatus;
            realtime_channel: string;
        };
        message: ConversationMessage;
    };
    occurred_at?: string;
}

interface ThreadChangedRealtimeEnvelope {
    event: 'mobile.conversation.thread.changed';
    data: Partial<ConversationThread> & {
        thread_id: number;
        type: ThreadType;
        status: ThreadStatus;
        realtime_channel: string;
    };
    occurred_at?: string;
}

const typeLabels: Record<ThreadType, string> = {
    direct: 'Direct',
    class_group: 'Group',
    school_channel: 'Channel',
};

const statusLabels: Record<ThreadStatus, string> = {
    open: 'Open',
    closed: 'Closed',
    archived: 'Archived',
};

const typeIcons = {
    direct: MessageSquareText,
    class_group: UsersRound,
    school_channel: Megaphone,
};

export default function ConversationsIndex({
    admin,
    csrfToken,
    threads: initialThreads,
    selectedThread: initialSelectedThread,
    messages: initialMessages,
    realtime,
}: Props) {
    const [threads, setThreads] =
        useState<ConversationThread[]>(initialThreads);
    const [selectedThread, setSelectedThread] =
        useState<ConversationThread | null>(initialSelectedThread ?? null);
    const [messages, setMessages] =
        useState<ConversationMessage[]>(initialMessages);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState<'all' | ThreadType>('all');
    const [statusFilter, setStatusFilter] = useState<'active' | ThreadStatus>(
        'active',
    );
    const [draft, setDraft] = useState('');
    const [loadingThreadId, setLoadingThreadId] = useState<number | null>(null);
    const [sending, setSending] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [liveStatus, setLiveStatus] = useState<LiveStatus>(
        realtime.enabled && realtime.connection.key ? 'connecting' : 'disabled',
    );

    const pusherRef = useRef<Pusher | null>(null);
    const subscribedChannelsRef = useRef<Set<string>>(new Set());

    const selectedThreadRef = useRef<ConversationThread | null>(selectedThread);
    const threadsRef = useRef<ConversationThread[]>(threads);

    useEffect(() => {
        selectedThreadRef.current = selectedThread;
    }, [selectedThread]);

    useEffect(() => {
        threadsRef.current = threads;
    }, [threads]);

    const requestJson = useCallback(
        async <T,>(url: string, options: RequestInit = {}): Promise<T> => {
            const response = await fetch(url, {
                ...options,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                    ...(options.headers ?? {}),
                },
            });

            const payload = (await response.json()) as
                | ApiResponse<T>
                | { message?: string };

            if (!response.ok) {
                throw new Error(
                    'message' in payload && payload.message
                        ? payload.message
                        : 'Request failed.',
                );
            }

            return (payload as ApiResponse<T>).data;
        },
        [csrfToken],
    );

    const mergeThread = useCallback((incoming: ConversationThread) => {
        setThreads((current) => {
            const existing = current.find(
                (thread) => thread.id === incoming.id,
            );
            const merged = existing ? { ...existing, ...incoming } : incoming;
            const rest = current.filter((thread) => thread.id !== incoming.id);

            return [merged, ...rest].sort((a, b) => {
                const aTime = new Date(a.last_message_at ?? 0).getTime();
                const bTime = new Date(b.last_message_at ?? 0).getTime();

                return bTime - aTime || b.id - a.id;
            });
        });

        setSelectedThread((current) =>
            current?.id === incoming.id ? { ...current, ...incoming } : current,
        );
    }, []);

    const appendMessage = useCallback((message: ConversationMessage) => {
        setMessages((current) => {
            if (current.some((existing) => existing.id === message.id)) {
                return current;
            }

            return [...current, message].sort((a, b) => a.id - b.id);
        });
    }, []);

    const fetchThread = useCallback(
        async (threadId: number, selectThread = true) => {
            setLoadingThreadId(threadId);

            try {
                const data = await requestJson<{
                    thread: ConversationThread;
                    messages: ConversationMessage[];
                }>(`/admin/conversations/${threadId}`);

                mergeThread(data.thread);

                if (
                    selectThread ||
                    selectedThreadRef.current?.id === threadId
                ) {
                    setSelectedThread(data.thread);
                    setMessages(data.messages);
                }
            } finally {
                setLoadingThreadId(null);
            }
        },
        [mergeThread, requestJson],
    );

    const subscribeToChannels = useCallback(
        (channels: string[]) => {
            const pusher = pusherRef.current;

            if (!pusher) {
                return;
            }

            channels.filter(Boolean).forEach((channelName) => {
                if (subscribedChannelsRef.current.has(channelName)) {
                    return;
                }

                const channel = pusher.subscribe(channelName);
                subscribedChannelsRef.current.add(channelName);

                const handleMessage = (payload: MessageRealtimeEnvelope) => {
                    const threadId = payload.data.thread.id;
                    const incomingThread = {
                        ...payload.data.thread,
                        last_message: payload.data.message,
                        last_message_at:
                            payload.data.thread.last_message_at ??
                            payload.data.message.sent_at ??
                            null,
                    } as ConversationThread;

                    subscribeToChannels([incomingThread.realtime_channel]);

                    const knownThread = threadsRef.current.some(
                        (thread) => thread.id === threadId,
                    );
                    if (!knownThread || !incomingThread.school) {
                        void fetchThread(
                            threadId,
                            selectedThreadRef.current?.id === threadId,
                        );
                    }

                    mergeThread(incomingThread);

                    if (selectedThreadRef.current?.id === threadId) {
                        appendMessage(payload.data.message);
                    }
                };

                const handleThreadChanged = (
                    payload: ThreadChangedRealtimeEnvelope,
                ) => {
                    setThreads((current) =>
                        current.map((thread) =>
                            thread.id === payload.data.thread_id
                                ? {
                                      ...thread,
                                      status: payload.data.status,
                                      can_post: payload.data.status === 'open',
                                  }
                                : thread,
                        ),
                    );
                    setSelectedThread((current) =>
                        current?.id === payload.data.thread_id
                            ? {
                                  ...current,
                                  status: payload.data.status,
                                  can_post: payload.data.status === 'open',
                              }
                            : current,
                    );
                };

                channel.bind(
                    'mobile.conversation.message.created',
                    handleMessage,
                );
                channel.bind(
                    '.mobile.conversation.message.created',
                    handleMessage,
                );
                channel.bind(
                    'mobile.conversation.thread.changed',
                    handleThreadChanged,
                );
                channel.bind(
                    '.mobile.conversation.thread.changed',
                    handleThreadChanged,
                );
            });
        },
        [appendMessage, fetchThread, mergeThread],
    );

    useEffect(() => {
        if (!realtime.enabled || !realtime.connection.key) {
            setLiveStatus('disabled');
            return;
        }

        const scheme =
            realtime.connection.scheme === 'https' ? 'https' : 'http';
        const port = Number(
            realtime.connection.port || (scheme === 'https' ? 443 : 80),
        );

        const pusher = new Pusher(realtime.connection.key, {
            cluster: 'mt1',
            forceTLS: scheme === 'https',
            wsHost: realtime.connection.host || window.location.hostname,
            wsPort: port,
            wssPort: port,
            enabledTransports: scheme === 'https' ? ['wss'] : ['ws', 'wss'],
            channelAuthorization: {
                endpoint: realtime.authEndpoint,
                transport: 'ajax',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        });

        pusherRef.current = pusher;
        setLiveStatus('connecting');

        pusher.connection.bind('connected', () => setLiveStatus('connected'));
        pusher.connection.bind('unavailable', () =>
            setLiveStatus('unavailable'),
        );
        pusher.connection.bind('failed', () => setLiveStatus('error'));
        pusher.connection.bind('error', () => setLiveStatus('error'));

        subscribeToChannels(realtime.channels);

        return () => {
            pusher.disconnect();
            pusherRef.current = null;
            subscribedChannelsRef.current.clear();
        };
    }, [csrfToken, realtime, subscribeToChannels]);

    useEffect(() => {
        subscribeToChannels(threads.map((thread) => thread.realtime_channel));
    }, [subscribeToChannels, threads]);

    const refreshThreads = useCallback(async () => {
        setRefreshing(true);

        try {
            const params = new URLSearchParams({
                limit: '80',
            });

            if (typeFilter !== 'all') {
                params.set('type', typeFilter);
            }

            if (statusFilter !== 'active') {
                params.set('status', statusFilter);
            }

            if (search.trim()) {
                params.set('search', search.trim());
            }

            const data = await requestJson<{
                items: ConversationThread[];
                realtime_channels: string[];
            }>(`/admin/conversations/list?${params.toString()}`);

            setThreads(data.items);
            subscribeToChannels(data.realtime_channels);

            if (
                selectedThreadRef.current &&
                !data.items.some(
                    (thread) => thread.id === selectedThreadRef.current?.id,
                )
            ) {
                const next = data.items[0] ?? null;
                setSelectedThread(next);
                setMessages([]);

                if (next) {
                    await fetchThread(next.id, true);
                }
            }
        } finally {
            setRefreshing(false);
        }
    }, [
        fetchThread,
        requestJson,
        search,
        statusFilter,
        subscribeToChannels,
        typeFilter,
    ]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void refreshThreads();
        }, 350);

        return () => window.clearTimeout(timer);
    }, [refreshThreads]);

    const submitMessage = async (event: FormEvent) => {
        event.preventDefault();

        if (!selectedThread || !draft.trim()) {
            return;
        }

        setSending(true);

        try {
            const data = await requestJson<{
                message: ConversationMessage;
                thread: ConversationThread;
            }>(`/admin/conversations/${selectedThread.id}/messages`, {
                method: 'POST',
                body: JSON.stringify({ body: draft.trim() }),
            });

            setDraft('');
            mergeThread(data.thread);
            setSelectedThread(data.thread);
            appendMessage(data.message);
        } finally {
            setSending(false);
        }
    };

    const markRead = async () => {
        if (!selectedThread) {
            return;
        }

        await requestJson<{ marked_read: number }>(
            `/admin/conversations/${selectedThread.id}/read`,
            {
                method: 'POST',
                body: JSON.stringify({}),
            },
        );

        const update = (thread: ConversationThread): ConversationThread =>
            thread.id === selectedThread.id
                ? { ...thread, unread_count: 0 }
                : thread;

        setThreads((current) => current.map(update));
        setSelectedThread((current) => (current ? update(current) : current));
    };

    const changeStatus = async (status: ThreadStatus) => {
        if (!selectedThread || selectedThread.status === status) {
            return;
        }

        const data = await requestJson<{ thread: ConversationThread }>(
            `/admin/conversations/${selectedThread.id}/status`,
            {
                method: 'PATCH',
                body: JSON.stringify({ status }),
            },
        );

        mergeThread(data.thread);
        setSelectedThread(data.thread);
    };

    const filteredThreads = useMemo(() => threads, [threads]);
    const canSend =
        selectedThread?.can_post &&
        selectedThread.status === 'open' &&
        draft.trim().length > 0 &&
        !sending;
    const adminForLayout = useMemo(
        () => ({ ...admin, school: admin.school ?? undefined }),
        [admin],
    );

    const totalUnread = useMemo(() => {
        return threads.reduce(
            (total, thread) => total + (thread.unread_count || 0),
            0,
        );
    }, [threads]);

    return (
        <AdminLayout admin={adminForLayout}>
            <Head title="Conversations" />

            <div className="space-y-5">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Conversations
                        </h1>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <LiveBadge
                                status={liveStatus}
                                driver={realtime.driver}
                            />
                            <Badge
                                variant={
                                    totalUnread > 0 ? 'default' : 'secondary'
                                }
                            >
                                {totalUnread} unread
                            </Badge>
                            <Badge variant="outline">
                                {filteredThreads.length} threads
                            </Badge>
                        </div>
                    </div>

                    <Button
                        variant="outline"
                        onClick={() => void refreshThreads()}
                        disabled={refreshing}
                    >
                        {refreshing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <RefreshCw className="h-4 w-4" />
                        )}
                        Refresh
                    </Button>
                </div>

                <div className="grid min-h-[680px] gap-4 xl:grid-cols-[380px_minmax(0,1fr)]">
                    <aside className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div className="border-b border-gray-200 p-4">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    className="pl-9"
                                    placeholder="Search conversations"
                                />
                            </div>

                            <div className="mt-3 grid grid-cols-2 gap-2">
                                <Select
                                    value={typeFilter}
                                    onValueChange={(value) =>
                                        setTypeFilter(
                                            value as 'all' | ThreadType,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All types
                                        </SelectItem>
                                        <SelectItem value="direct">
                                            Direct
                                        </SelectItem>
                                        <SelectItem value="class_group">
                                            Groups
                                        </SelectItem>
                                        <SelectItem value="school_channel">
                                            Channels
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={statusFilter}
                                    onValueChange={(value) =>
                                        setStatusFilter(
                                            value as 'active' | ThreadStatus,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="open">
                                            Open
                                        </SelectItem>
                                        <SelectItem value="closed">
                                            Closed
                                        </SelectItem>
                                        <SelectItem value="archived">
                                            Archived
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="max-h-[620px] overflow-y-auto">
                            {filteredThreads.length === 0 ? (
                                <div className="flex min-h-[360px] flex-col items-center justify-center px-6 text-center">
                                    <Inbox className="h-10 w-10 text-gray-300" />
                                    <p className="mt-3 text-sm font-medium text-gray-900">
                                        No conversations
                                    </p>
                                </div>
                            ) : (
                                filteredThreads.map((thread) => (
                                    <ThreadListItem
                                        key={thread.id}
                                        thread={thread}
                                        active={
                                            selectedThread?.id === thread.id
                                        }
                                        loading={loadingThreadId === thread.id}
                                        onSelect={() =>
                                            void fetchThread(thread.id, true)
                                        }
                                    />
                                ))
                            )}
                        </div>
                    </aside>

                    <section className="flex min-w-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        {selectedThread ? (
                            <>
                                <div className="border-b border-gray-200 px-5 py-4">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <ThreadTypeBadge
                                                    type={selectedThread.type}
                                                />
                                                <ThreadStatusBadge
                                                    status={
                                                        selectedThread.status
                                                    }
                                                />
                                                {selectedThread.unread_count >
                                                    0 && (
                                                    <Badge>
                                                        {
                                                            selectedThread.unread_count
                                                        }{' '}
                                                        unread
                                                    </Badge>
                                                )}
                                            </div>
                                            <h2 className="mt-2 truncate text-xl font-semibold text-gray-900">
                                                {selectedThread.title}
                                            </h2>
                                            <ThreadMeta
                                                thread={selectedThread}
                                            />
                                        </div>

                                        <div className="grid min-w-[260px] grid-cols-[1fr_auto] gap-2">
                                            <Select
                                                value={selectedThread.status}
                                                onValueChange={(value) =>
                                                    void changeStatus(
                                                        value as ThreadStatus,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="open">
                                                        Open
                                                    </SelectItem>
                                                    <SelectItem value="closed">
                                                        Closed
                                                    </SelectItem>
                                                    <SelectItem value="archived">
                                                        Archived
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <Button
                                                variant="outline"
                                                onClick={() => void markRead()}
                                            >
                                                <CheckCheck className="h-4 w-4" />
                                                Read
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex-1 overflow-y-auto bg-gray-50 px-4 py-5">
                                    <div className="mx-auto flex max-w-4xl flex-col gap-3">
                                        {messages.length === 0 ? (
                                            <div className="flex min-h-[360px] items-center justify-center text-sm text-gray-500">
                                                No messages yet
                                            </div>
                                        ) : (
                                            messages.map((message) => (
                                                <MessageBubble
                                                    key={message.id}
                                                    message={message}
                                                />
                                            ))
                                        )}
                                    </div>
                                </div>

                                <form
                                    onSubmit={submitMessage}
                                    className="border-t border-gray-200 bg-white p-4"
                                >
                                    <div className="flex flex-col gap-3 lg:flex-row">
                                        <Textarea
                                            value={draft}
                                            onChange={(event) =>
                                                setDraft(event.target.value)
                                            }
                                            className="min-h-[84px] resize-none"
                                            placeholder={
                                                selectedThread.can_post
                                                    ? 'Reply to this conversation'
                                                    : 'This conversation is not open'
                                            }
                                            disabled={
                                                !selectedThread.can_post ||
                                                sending
                                            }
                                        />
                                        <Button
                                            type="submit"
                                            className="self-end"
                                            disabled={!canSend}
                                        >
                                            {sending ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <Send className="h-4 w-4" />
                                            )}
                                            Send
                                        </Button>
                                    </div>
                                </form>
                            </>
                        ) : (
                            <div className="flex min-h-[680px] flex-col items-center justify-center text-center">
                                <MessageSquareText className="h-12 w-12 text-gray-300" />
                                <p className="mt-3 text-sm font-medium text-gray-900">
                                    No thread selected
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}

function LiveBadge({
    status,
    driver,
}: {
    status: LiveStatus;
    driver?: string | null;
}) {
    const label = {
        connected: 'Live',
        connecting: 'Connecting',
        unavailable: 'Unavailable',
        error: 'Realtime error',
        disabled: 'Realtime off',
    }[status];

    const variant =
        status === 'connected'
            ? 'success'
            : status === 'disabled'
              ? 'secondary'
              : 'outline';
    const Icon = status === 'connected' ? Wifi : WifiOff;

    return (
        <Badge variant={variant}>
            <Icon className="h-3.5 w-3.5" />
            {label}
            {driver ? `: ${driver}` : ''}
        </Badge>
    );
}

function ThreadListItem({
    thread,
    active,
    loading,
    onSelect,
}: {
    thread: ConversationThread;
    active: boolean;
    loading: boolean;
    onSelect: () => void;
}) {
    const Icon = typeIcons[thread.type];

    return (
        <button
            type="button"
            onClick={onSelect}
            className={`grid w-full grid-cols-[40px_minmax(0,1fr)_auto] gap-3 border-b border-gray-100 px-4 py-3 text-left transition-colors ${
                active ? 'bg-emerald-50' : 'bg-white hover:bg-gray-50'
            }`}
        >
            <span
                className={`flex h-10 w-10 items-center justify-center rounded-md ${
                    active
                        ? 'bg-emerald-600 text-white'
                        : 'bg-gray-100 text-gray-600'
                }`}
            >
                {loading ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                    <Icon className="h-4 w-4" />
                )}
            </span>
            <span className="min-w-0">
                <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-sm font-semibold text-gray-900">
                        {thread.title}
                    </span>
                    <ThreadStatusDot status={thread.status} />
                </span>
                <span className="mt-1 block truncate text-xs text-gray-500">
                    {thread.last_message?.body ??
                        thread.school?.name ??
                        typeLabels[thread.type]}
                </span>
                <span className="mt-1 block truncate text-xs text-gray-400">
                    {formatDateTime(thread.last_message_at)}
                </span>
            </span>
            <span className="flex flex-col items-end gap-2">
                <ThreadTypeBadge type={thread.type} compact />
                {thread.unread_count > 0 && (
                    <Badge>{thread.unread_count}</Badge>
                )}
            </span>
        </button>
    );
}

function ThreadTypeBadge({
    type,
    compact = false,
}: {
    type: ThreadType;
    compact?: boolean;
}) {
    const Icon = typeIcons[type];

    return (
        <Badge variant="outline" className={compact ? 'px-1.5' : undefined}>
            <Icon className="h-3.5 w-3.5" />
            {compact ? null : typeLabels[type]}
        </Badge>
    );
}

function ThreadStatusBadge({ status }: { status: ThreadStatus }) {
    if (status === 'open') {
        return (
            <Badge variant="success">
                <Circle className="h-3.5 w-3.5 fill-current" />
                Open
            </Badge>
        );
    }

    if (status === 'closed') {
        return (
            <Badge variant="secondary">
                <Lock className="h-3.5 w-3.5" />
                Closed
            </Badge>
        );
    }

    return (
        <Badge variant="outline">
            <Archive className="h-3.5 w-3.5" />
            Archived
        </Badge>
    );
}

function ThreadStatusDot({ status }: { status: ThreadStatus }) {
    const color =
        status === 'open'
            ? 'bg-emerald-500'
            : status === 'closed'
              ? 'bg-amber-500'
              : 'bg-gray-400';

    return (
        <span
            className={`h-2 w-2 shrink-0 rounded-full ${color}`}
            title={statusLabels[status]}
        />
    );
}

function ThreadMeta({ thread }: { thread: ConversationThread }) {
    const bits = [
        thread.school?.name,
        thread.class?.full_name ?? thread.class?.name,
        thread.student?.full_name,
    ].filter(Boolean);

    if (bits.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
            {thread.school?.name && (
                <span className="inline-flex items-center gap-1">
                    <School className="h-3.5 w-3.5" />
                    {thread.school.name}
                </span>
            )}
            {(thread.class?.full_name ?? thread.class?.name) && (
                <span>{thread.class?.full_name ?? thread.class?.name}</span>
            )}
            {thread.student?.full_name && (
                <span>{thread.student.full_name}</span>
            )}
        </div>
    );
}

function MessageBubble({ message }: { message: ConversationMessage }) {
    return (
        <div
            className={`flex ${message.own_message ? 'justify-end' : 'justify-start'}`}
        >
            <div
                className={`max-w-[min(760px,85%)] rounded-lg px-4 py-3 shadow-sm ${
                    message.own_message
                        ? 'bg-emerald-600 text-white'
                        : message.sender_type === 'admin'
                          ? 'bg-white text-gray-900 ring-1 ring-gray-200'
                          : 'bg-sky-50 text-gray-900 ring-1 ring-sky-100'
                }`}
            >
                <div className="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs opacity-80">
                    <span className="font-medium">
                        {message.sender_display_name}
                    </span>
                    <span>{formatDateTime(message.sent_at)}</span>
                </div>
                <p className="text-sm leading-6 break-words whitespace-pre-wrap">
                    {message.body}
                </p>
            </div>
        </div>
    );
}

function formatDateTime(value?: string | null): string {
    if (!value) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(value));
    } catch {
        return value;
    }
}
