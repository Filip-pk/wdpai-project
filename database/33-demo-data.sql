BEGIN;

-- Konta i aktywność demonstracyjna do lokalnego uruchomienia i prezentacji projektu.
-- Hasła są opisane w README.md (sekcja „Konta demonstracyjne”). Na publicznym
-- wdrożeniu zmień je albo usuń te konta. Skrypt można uruchomić ponownie:
-- nie tworzy duplikatów i nie zmienia istniejących kont.
-- Anime są wskazywane przez mal_id sześciu tytułów z migracji 05, więc dane
-- pasują również po pełnym imporcie katalogu.

INSERT INTO public.users (name, email, password_hash, interests, language, is_moderator, is_admin)
VALUES
    ('Demo Użytkownik', 'user@demo.anivibe.test',
        '$2y$10$UAMoFi1KiDb4YASkoC3V7.iztPc2LlVBytbpZunit1qAuk2H5NHPC',
        'Thrillery psychologiczne, podróże w czasie i klasyczne shōnen.', 'pl', FALSE, FALSE),
    ('Demo Moderator', 'moderator@demo.anivibe.test',
        '$2y$10$GQ7Sk8rMSnts40KuuboNkOOKTphZmzK3ffXGwEnZkRcpfAgIAtvwK',
        'Dbam o kulturę dyskusji pod anime.', 'pl', TRUE, FALSE),
    ('Demo Administrator', 'admin@demo.anivibe.test',
        '$2y$10$NgjZKAtz9NWwi3WLBxp0hOwYQ1eHFkv0DXGdSj5nxMjU3Ev.p7n7u',
        'Diagnostyka, role i zgłoszenia wiadomości.', 'pl', FALSE, TRUE),
    ('Demo Spamer', 'spammer@demo.anivibe.test',
        '$2y$10$Tr9qKe/6oAYkGiennHUZmOs4yOaLg5o7Ppv3i0PtE2kRS4UDVhUAi',
        'Konto do pokazania zgłoszeń i moderacji.', 'pl', FALSE, FALSE)
ON CONFLICT DO NOTHING;

-- Rola konta to część adresu przed znakiem @, np. „user” albo „moderator”.
-- Przy pierwszym uruchomieniu tabeli tymczasowej nie ma; pomijamy ten komunikat.
SET LOCAL client_min_messages = warning;
DROP TABLE IF EXISTS pg_temp.demo_account;
CREATE TEMP TABLE demo_account ON COMMIT DROP AS
SELECT id, split_part(LOWER(email), '@', 1) AS role
FROM public.users
WHERE LOWER(email) IN ('user@demo.anivibe.test', 'moderator@demo.anivibe.test',
    'admin@demo.anivibe.test', 'spammer@demo.anivibe.test');

-- Watchlista i EXP liczone tak jak w aplikacji: obejrzane odcinki i ukończone tytuły.
INSERT INTO public.user_watchlist (user_id, anime_id, status, watched)
SELECT account.id, anime.id, entry.status, entry.watched
FROM (VALUES
    ('user', 20, 'completed', 220),
    ('user', 1535, 'completed', 37),
    ('user', 9253, 'watching', 12),
    ('user', 42310, 'on_hold', 4),
    ('user', 44511, 'planned', 0),
    ('moderator', 16498, 'completed', 25),
    ('moderator', 9253, 'completed', 24),
    ('admin', 1535, 'watching', 10)
) AS entry (role, mal_id, status, watched)
JOIN demo_account account ON account.role = entry.role
JOIN public.anime anime ON anime.mal_id = entry.mal_id
ON CONFLICT DO NOTHING;

INSERT INTO public.user_anime_experience (user_id, anime_id, watched, completed)
SELECT watchlist.user_id, watchlist.anime_id, watchlist.watched, watchlist.status = 'completed'
FROM public.user_watchlist watchlist
JOIN demo_account account ON account.id = watchlist.user_id
ON CONFLICT DO NOTHING;

INSERT INTO public.user_anime_ratings (user_id, anime_id, rating)
SELECT account.id, anime.id, entry.rating
FROM (VALUES
    ('user', 20, 8),
    ('user', 1535, 10),
    ('user', 9253, 9),
    ('moderator', 16498, 9),
    ('moderator', 9253, 10),
    ('admin', 1535, 9)
) AS entry (role, mal_id, rating)
JOIN demo_account account ON account.role = entry.role
JOIN public.anime anime ON anime.mal_id = entry.mal_id
ON CONFLICT DO NOTHING;

INSERT INTO public.anime_collections (user_id, name, description)
SELECT account.id, 'Thrillery z twistem', 'Tytuły, do których chcę wracać.'
FROM demo_account account
WHERE account.role = 'user'
    AND NOT EXISTS (SELECT 1 FROM public.anime_collections collection
        WHERE collection.user_id = account.id AND collection.name = 'Thrillery z twistem');

INSERT INTO public.anime_collection_items (collection_id, anime_id)
SELECT collection.id, anime.id
FROM public.anime_collections collection
JOIN demo_account account ON account.id = collection.user_id AND account.role = 'user'
JOIN public.anime anime ON anime.mal_id IN (1535, 9253)
WHERE collection.name = 'Thrillery z twistem'
ON CONFLICT DO NOTHING;

-- Komentarze. Spoiler spamera czeka na decyzję moderatora.
INSERT INTO public.anime_comments (anime_id, user_id, body, created_at)
SELECT anime.id, account.id, entry.body, CURRENT_TIMESTAMP - entry.age
FROM (VALUES
    ('user', 1535, 'Pojedynek Lighta i L to dla mnie najlepsza część całej serii.', INTERVAL '3 days'),
    ('user', 9253, 'Spokojny początek, ale ostatnie odcinki wynagradzają wszystko. Warto dać szansę!', INTERVAL '2 days'),
    ('spammer', 44511, 'SPOILER: w ostatnim odcinku sezonu ginie najważniejsza postać!', INTERVAL '1 day')
) AS entry (role, mal_id, body, age)
JOIN demo_account account ON account.role = entry.role
JOIN public.anime anime ON anime.mal_id = entry.mal_id
WHERE NOT EXISTS (SELECT 1 FROM public.anime_comments comment
    WHERE comment.user_id = account.id AND comment.anime_id = anime.id AND comment.body = entry.body);

INSERT INTO public.anime_comments (anime_id, user_id, parent_id, body, created_at)
SELECT root.anime_id, moderator.id, root.id,
    'Zgadzam się, szczególnie odcinki w połowie serii.', CURRENT_TIMESTAMP - INTERVAL '2 days'
FROM public.anime_comments root
JOIN demo_account author ON author.id = root.user_id AND author.role = 'user'
JOIN demo_account moderator ON moderator.role = 'moderator'
WHERE root.parent_id IS NULL
    AND root.body = 'Pojedynek Lighta i L to dla mnie najlepsza część całej serii.'
    AND NOT EXISTS (SELECT 1 FROM public.anime_comments reply
        WHERE reply.parent_id = root.id AND reply.user_id = moderator.id);

INSERT INTO public.comment_reply_notifications (user_id, reply_id, created_at)
SELECT root.user_id, reply.id, reply.created_at
FROM public.anime_comments reply
JOIN public.anime_comments root ON root.id = reply.parent_id
JOIN demo_account author ON author.id = reply.user_id AND author.role = 'moderator'
ON CONFLICT DO NOTHING;

INSERT INTO public.comment_reactions (user_id, comment_id, value)
SELECT voter.id, comment.id, 1
FROM public.anime_comments comment
JOIN demo_account author ON author.id = comment.user_id AND author.role = 'user'
JOIN demo_account voter ON voter.role IN ('moderator', 'admin')
WHERE comment.parent_id IS NULL
ON CONFLICT DO NOTHING;

-- Rozmowy: zwykła z moderatorem i spam, który czeka na decyzję administratora.
INSERT INTO public.private_conversations (first_user_id, second_user_id, created_at)
SELECT LEAST(member.id, other.id), GREATEST(member.id, other.id), CURRENT_TIMESTAMP - INTERVAL '2 days'
FROM demo_account member
JOIN demo_account other ON other.role IN ('moderator', 'spammer')
WHERE member.role = 'user'
ON CONFLICT DO NOTHING;

INSERT INTO public.private_messages (conversation_id, sender_id, body, created_at)
SELECT conversation.id, sender.id, entry.body, CURRENT_TIMESTAMP - entry.age
FROM (VALUES
    ('user', 'moderator', 'Cześć! Polecisz coś podobnego do Steins;Gate?', INTERVAL '48 hours'),
    ('moderator', 'moderator', 'Spróbuj Death Note: też ma mnóstwo zwrotów akcji.', INTERVAL '47 hours'),
    ('spammer', 'spammer', 'Darmowe konto premium! Wejdź na http://spam.example i podaj swoje hasło.', INTERVAL '20 hours')
) AS entry (sender_role, partner_role, body, age)
JOIN demo_account sender ON sender.role = entry.sender_role
JOIN demo_account member ON member.role = 'user'
JOIN demo_account partner ON partner.role = entry.partner_role
JOIN public.private_conversations conversation
    ON conversation.first_user_id = LEAST(member.id, partner.id)
    AND conversation.second_user_id = GREATEST(member.id, partner.id)
WHERE NOT EXISTS (SELECT 1 FROM public.private_messages message
    WHERE message.conversation_id = conversation.id AND message.sender_id = sender.id AND message.body = entry.body)
ORDER BY entry.age DESC;

-- Zgłoszenia od konta użytkownika: komentarz trafia do moderatora, wiadomość do administratora.
INSERT INTO public.community_reports (reporter_id, reported_user_id, target_type, target_id, snapshot, reason)
SELECT reporter.id, comment.user_id, 'comment', comment.id, comment.body, 'Spoiler zakończenia bez ostrzeżenia.'
FROM public.anime_comments comment
JOIN demo_account author ON author.id = comment.user_id AND author.role = 'spammer'
JOIN demo_account reporter ON reporter.role = 'user'
WHERE comment.parent_id IS NULL
ON CONFLICT DO NOTHING;

INSERT INTO public.community_reports (reporter_id, reported_user_id, target_type, target_id, snapshot, reason)
SELECT reporter.id, message.sender_id, 'message', message.id, message.body, 'Spam i próba wyłudzenia hasła.'
FROM public.private_messages message
JOIN demo_account sender ON sender.id = message.sender_id AND sender.role = 'spammer'
JOIN demo_account reporter ON reporter.role = 'user'
ON CONFLICT DO NOTHING;

COMMIT;
