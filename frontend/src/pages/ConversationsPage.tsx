import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, CheckCheck, Loader2, MessageSquareText, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import EmptyState from '../components/EmptyState';
import LoadingState from '../components/LoadingState';
import StatusBadge from '../components/StatusBadge';
import { adminApi, errorMessage } from '../lib/api';
import { formatDate, titleCase } from '../lib/format';

const filters = [
  { label: 'All', value: '' },
  { label: 'Class Groups', value: 'class_group' },
  { label: 'School Channels', value: 'school_channel' },
  { label: 'Direct', value: 'direct' },
];

export default function ConversationsPage() {
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [body, setBody] = useState('');

  const threadsQuery = useQuery({
    queryKey: ['conversations', filter],
    queryFn: () => adminApi.conversations(filter || undefined),
  });

  const threads = useMemo(() => threadsQuery.data ?? [], [threadsQuery.data]);
  const effectiveSelectedId = threads.some((thread) => thread.id === selectedId) ? selectedId : threads[0]?.id ?? null;

  const selectedQuery = useQuery({
    queryKey: ['conversation', effectiveSelectedId],
    queryFn: () => adminApi.conversation(effectiveSelectedId as number),
    enabled: effectiveSelectedId !== null,
  });

  const postMutation = useMutation({
    mutationFn: () => adminApi.postMessage(effectiveSelectedId as number, body),
    onSuccess: () => {
      setBody('');
      void queryClient.invalidateQueries({ queryKey: ['conversation', effectiveSelectedId] });
      void queryClient.invalidateQueries({ queryKey: ['conversations'] });
    },
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'open' | 'closed' | 'archived' }) =>
      adminApi.updateConversationStatus(id, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['conversation', effectiveSelectedId] });
      void queryClient.invalidateQueries({ queryKey: ['conversations'] });
    },
  });

  function handleSend(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!body.trim() || effectiveSelectedId === null) {
      return;
    }

    postMutation.mutate();
  }

  if (threadsQuery.isLoading) {
    return <LoadingState label="Loading conversations" />;
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Parent communication</p>
          <h1>Conversations</h1>
          <p>Class groups and school channels are system-managed from enrollment, with admins joining the relevant spaces.</p>
        </div>
      </section>

      <section className="conversation-shell">
        <aside className="thread-list panel">
          <div className="filter-tabs">
            {filters.map((item) => (
              <button
                type="button"
                key={item.value}
                className={filter === item.value ? 'active' : ''}
                onClick={() => setFilter(item.value)}
              >
                {item.label}
              </button>
            ))}
          </div>

          {threads.length === 0 ? (
            <EmptyState icon={MessageSquareText} title="No conversations" message="Threads will appear after parents link children or messages are synced." />
          ) : (
            <div className="thread-stack">
              {threads.map((thread) => (
                <button
                  className={`thread-button ${effectiveSelectedId === thread.id ? 'active' : ''}`}
                  type="button"
                  key={thread.id}
                  onClick={() => setSelectedId(thread.id)}
                >
                  <div>
                    <strong>{thread.title}</strong>
                    <span>{thread.school?.name ?? titleCase(thread.type)}</span>
                  </div>
                  <StatusBadge status={thread.status} />
                </button>
              ))}
            </div>
          )}
        </aside>

        <div className="conversation-panel panel">
          {selectedQuery.isLoading ? (
            <LoadingState label="Loading thread" />
          ) : selectedQuery.data ? (
            <>
              <div className="conversation-header">
                <div>
                  <h2>{selectedQuery.data.thread.title}</h2>
                  <p>
                    {titleCase(selectedQuery.data.thread.type)} · {selectedQuery.data.thread.school?.name ?? 'No school'}
                  </p>
                </div>
                <div className="button-row">
                  <button
                    className="secondary-button"
                    type="button"
                    onClick={() => statusMutation.mutate({ id: selectedQuery.data.thread.id, status: 'closed' })}
                    disabled={statusMutation.isPending || selectedQuery.data.thread.status === 'closed'}
                  >
                    <CheckCheck size={16} />
                    Close
                  </button>
                  <button
                    className="secondary-button"
                    type="button"
                    onClick={() => statusMutation.mutate({ id: selectedQuery.data.thread.id, status: 'archived' })}
                    disabled={statusMutation.isPending || selectedQuery.data.thread.status === 'archived'}
                  >
                    <Archive size={16} />
                    Archive
                  </button>
                </div>
              </div>

              <div className="message-list">
                {selectedQuery.data.messages.length === 0 ? (
                  <EmptyState icon={MessageSquareText} title="No messages yet" message="Messages from parents and admins will appear here." />
                ) : (
                  selectedQuery.data.messages.map((message) => (
                    <div className={`message-bubble ${message.own_message ? 'own' : ''}`} key={message.id}>
                      <div className="message-meta">
                        <strong>{message.sender_display_name}</strong>
                        <span>{formatDate(message.sent_at)}</span>
                      </div>
                      <p>{message.body}</p>
                    </div>
                  ))
                )}
              </div>

              {postMutation.isError && <div className="form-alert">{errorMessage(postMutation.error, 'Unable to send this message.')}</div>}

              <form className="message-form" onSubmit={handleSend}>
                <textarea
                  value={body}
                  onChange={(event) => setBody(event.target.value)}
                  placeholder="Write a clear reply to parents..."
                  rows={3}
                />
                <button className="primary-button" type="submit" disabled={postMutation.isPending || !body.trim()}>
                  {postMutation.isPending ? <Loader2 size={18} className="spin" /> : <Send size={18} />}
                  Send
                </button>
              </form>
            </>
          ) : (
            <EmptyState icon={MessageSquareText} title="Select a conversation" message="Choose a thread to read messages and respond." />
          )}
        </div>
      </section>
    </div>
  );
}
