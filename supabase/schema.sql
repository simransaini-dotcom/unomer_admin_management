-- Team Fund Manager: database schema, validation rules and access control.
-- Run this whole file once in Supabase: Dashboard -> SQL Editor -> New query -> paste -> Run.
-- Then promote your admin account (see the end of this file).

-- ---------------------------------------------------------------- tables

create table if not exists public.employees (
  seq        bigint generated always as identity,
  id         text primary key default gen_random_uuid()::text,
  name       text not null check (char_length(btrim(name)) between 1 and 60),
  created_at timestamptz not null default now()
);
create unique index if not exists employees_name_key on public.employees (lower(name));

create table if not exists public.categories (
  seq        bigint generated always as identity,
  id         text primary key default gen_random_uuid()::text,
  name       text not null check (char_length(btrim(name)) between 1 and 40),
  kind       text not null check (kind in ('in', 'out')),
  fixed      boolean not null default false,
  amount     integer not null default 0 check (amount between 0 and 10000000),
  after_time text not null default ''
             check (after_time = '' or after_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
  color      integer not null default 0 check (color between 0 and 7),
  created_at timestamptz not null default now(),
  check ((fixed and amount > 0) or (not fixed and amount = 0)),
  check (kind = 'in' or after_time = '')
);
create unique index if not exists categories_name_key on public.categories (lower(name));

create table if not exists public.entries (
  seq        bigint generated always as identity,
  id         text primary key default gen_random_uuid()::text,
  cat_id     text not null references public.categories (id) on delete restrict,
  emp_id     text not null references public.employees (id) on delete cascade,
  kind       text not null check (kind in ('in', 'out')),
  entry_date date not null,
  entry_time text not null default ''
             check (entry_time = '' or entry_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
  amount     integer not null check (amount between 1 and 10000000),
  note       text not null default '' check (char_length(note) <= 200),
  created    bigint not null default (extract(epoch from now()) * 1000)::bigint,
  created_at timestamptz not null default now()
);
create index if not exists entries_emp_idx on public.entries (emp_id);
create index if not exists entries_cat_idx on public.entries (cat_id);

-- ---------------------------------------------------------------- business rules (enforced by the database)

create or replace function public.entries_guard() returns trigger
language plpgsql as $$
declare
  c public.categories;
begin
  select * into c from public.categories where id = new.cat_id;
  if not found then
    raise exception 'Unknown category';
  end if;
  new.kind := c.kind;
  if c.fixed then
    new.amount := c.amount;
  end if;
  if c.after_time <> '' then
    if new.entry_time = '' or new.entry_time <= c.after_time then
      raise exception 'Arrival time must be after %', c.after_time;
    end if;
  else
    new.entry_time := '';
  end if;
  return new;
end $$;

drop trigger if exists entries_guard on public.entries;
create trigger entries_guard before insert or update on public.entries
  for each row execute function public.entries_guard();

create or replace function public.categories_guard() returns trigger
language plpgsql as $$
begin
  if new.kind <> old.kind then
    raise exception 'A category type cannot be changed';
  end if;
  return new;
end $$;

drop trigger if exists categories_guard on public.categories;
create trigger categories_guard before update on public.categories
  for each row execute function public.categories_guard();

-- ---------------------------------------------------------------- access control
-- Everyone (even logged out) can READ. Only a user whose app_metadata role is 'admin' can WRITE.
-- app_metadata can only be changed from the Supabase dashboard / service key, never by a user.

create or replace function public.is_admin() returns boolean
language sql stable as $$
  select coalesce(auth.jwt() -> 'app_metadata' ->> 'role', '') = 'admin';
$$;

do $$
declare
  t text;
begin
  foreach t in array array['employees', 'categories', 'entries'] loop
    execute format('alter table public.%I enable row level security', t);

    execute format('drop policy if exists "read all" on public.%I', t);
    execute format('drop policy if exists "admin insert" on public.%I', t);
    execute format('drop policy if exists "admin update" on public.%I', t);
    execute format('drop policy if exists "admin delete" on public.%I', t);

    execute format('create policy "read all" on public.%I for select to anon, authenticated using (true)', t);
    execute format('create policy "admin insert" on public.%I for insert to authenticated with check (public.is_admin())', t);
    execute format('create policy "admin update" on public.%I for update to authenticated using (public.is_admin()) with check (public.is_admin())', t);
    execute format('create policy "admin delete" on public.%I for delete to authenticated using (public.is_admin())', t);

    execute format('revoke insert, update, delete, truncate on public.%I from anon', t);
  end loop;
end $$;

-- ---------------------------------------------------------------- starting categories

insert into public.categories (id, name, kind, fixed, amount, after_time, color) values
  ('cat_late',      'Late Penalty',           'in',  true,  120, '09:00', 0),
  ('cat_meeting',   'Meeting Penalty',        'in',  false, 0,   '',      1),
  ('cat_appraisal', 'Appraisal Contribution', 'in',  false, 0,   '',      2),
  ('cat_party_in',  'Party Contribution',     'in',  false, 0,   '',      3),
  ('cat_other',     'Other Penalty',          'in',  false, 0,   '',      4),
  ('cat_party',     'Party Expense',          'out', false, 0,   '',      5)
on conflict (id) do nothing;

-- ---------------------------------------------------------------- make YOUR account the admin
-- 1) Create the user first: Dashboard -> Authentication -> Users -> Add user -> Create new user
--    (enter your email + a strong password, tick "Auto Confirm User").
-- 2) Replace the email below with that email and run this statement on its own:
--
-- update auth.users
--    set raw_app_meta_data = coalesce(raw_app_meta_data, '{}'::jsonb) || '{"role": "admin"}'::jsonb
--  where email = 'your-admin-email@example.com';
--
-- 3) Dashboard -> Authentication -> Sign In / Providers -> turn OFF "Allow new users to sign up".
