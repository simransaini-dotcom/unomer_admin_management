# Team Fund Manager - Setup Steps

## Part 1: Create the Supabase project

1. Go to https://supabase.com and sign in.
2. Click **New project**.
3. Enter a project name, set a database password, choose a region, and click **Create new project**.
4. Wait until the project finishes setting up.

## Part 2: Create the database

1. In the Supabase dashboard, open **SQL Editor**.
2. Click **New query**.
3. Open the file `supabase/schema.sql` from this repository and copy its entire contents.
4. Paste the contents into the query editor.
5. Click **Run**.
6. Confirm the message "Success. No rows returned".
7. Open **Table Editor** and confirm the tables `employees`, `categories` and `entries` exist.
8. Open the `categories` table and confirm it contains 6 rows.

## Part 3: Create the admin account

1. In the Supabase dashboard, open **Authentication** -> **Users**.
2. Click **Add user** -> **Create new user**.
3. Enter the admin email and a strong password.
4. Tick **Auto Confirm User**.
5. Click **Create user**.
6. Open **SQL Editor** -> **New query**.
7. Paste the following statement, replace `your-admin-email@example.com` with the admin email from step 3, and click **Run**:

   ```sql
   update auth.users
      set raw_app_meta_data = coalesce(raw_app_meta_data, '{}'::jsonb) || '{"role": "admin"}'::jsonb
    where email = 'your-admin-email@example.com';
   ```

8. Confirm the result shows "Success. No rows returned".

## Part 4: Disable public sign-ups

1. In the Supabase dashboard, open **Authentication** -> **Sign In / Providers**.
2. Turn **off** "Allow new users to sign up".
3. Click **Save changes**.

## Part 5: Connect the app to Supabase

1. In the Supabase dashboard, open **Project Settings** -> **API Keys**.
2. Open the tab **Legacy anon, service_role API keys**.
3. Copy the **anon** key (a long text starting with `eyJ`).
4. Build the project URL: `https://<PROJECT_ID>.supabase.co`, where `<PROJECT_ID>` is the ID shown in the dashboard address bar (`supabase.com/dashboard/project/<PROJECT_ID>/...`) and under **Project Settings** -> **General**.
5. Open the file `supabase-config.js` in this repository.
6. Set `url` to the project URL from step 4.
7. Set `anonKey` to the anon key from step 3.
8. Save the file.
9. Do not copy the `service_role` key or any secret key into any file.

## Part 6: Test locally (optional)

1. Open a terminal in the project folder.
2. Run `python3 -m http.server 8000`.
3. Open `http://localhost:8000` in a browser.
4. Confirm the page loads with the header "Team Fund Manager" and the badge "View only".
5. Click **Admin Login**, enter the admin email and password, and click **Log in**.
6. Confirm the header shows "Admin: <email>" and the **New Transaction** card appears.
7. Click **Logout** and confirm the page returns to "View only".

## Part 7: Publish to GitHub

1. Open a terminal in the project folder.
2. Run `git add -A`.
3. Run `git commit -m "Setup"`.
4. Run `git push origin master`.

## Part 8: Enable GitHub Pages

1. Open the repository on GitHub.
2. Click **Settings** -> **Pages**.
3. Under **Build and deployment**, set **Source** to **Deploy from a branch**.
4. Set **Branch** to `master` and the folder to `/ (root)`.
5. Click **Save**.
6. Wait about one minute.
7. Open the published link: `https://<GITHUB_USERNAME>.github.io/<REPOSITORY_NAME>/`.

## Part 9: First use

1. Open the published link.
2. Click **Admin Login** and log in with the admin email and password.
3. If a prompt appears offering to import data saved in this browser, click **OK** to import it, or **Cancel** to skip.
4. Add employees in the **Employees** card.
5. Add or edit categories in the **Categories** card.
6. Record transactions in the **New Transaction** card.
7. Share the published link with employees. They see the data read-only.

## Part 10: Verify access control

1. Open the published link in a private browser window without logging in.
2. Confirm the page shows "View only".
3. Confirm the **New Transaction** card, the category and employee forms, and all Edit, Delete and Remove buttons are hidden.
4. Confirm the History table, the Employees table and **Export CSV** are visible and working.

## Part 11: Maintenance

### Reset the admin password
1. Open **Authentication** -> **Users** in the Supabase dashboard.
2. Click the admin user.
3. Click **Send password recovery** or set a new password.

### Add or change the admin
1. Create the user as in Part 3, steps 1-5.
2. Run the statement from Part 3, step 7 with the new email.

### Back up the data
1. Log in as admin on the published page.
2. In the History card, click **Export CSV**.
3. Save the file.
4. Optionally, in Supabase open **Database** -> **Backups** to view available backups.

## Troubleshooting

1. **Banner "Supabase is not configured yet"**: complete Part 5 and push the changes.
2. **Banner "Could not load the Supabase library"**: check the internet connection and that `https://cdn.jsdelivr.net` is reachable.
3. **Banner "Could not load data"**: confirm Part 2 was completed and that the `url` and `anonKey` in `supabase-config.js` match the project.
4. **Login shows "Invalid email or password"**: confirm the user exists in **Authentication** -> **Users** and that **Auto Confirm User** was ticked.
5. **Login shows "This account does not have admin access"**: run the statement from Part 3, step 7 with the correct email, then log in again.
6. **Changes fail with "Only the admin can make changes"**: log out, log in again as admin.
7. **Published page shows an old version**: wait one minute and hard refresh the browser (Ctrl+Shift+R).
