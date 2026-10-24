# Deploy Oracle to cPanel

Production: https://sksu-oracle.com/

## Repository and website locations

Keep the cPanel-managed clone separate from the existing website:

| Setting | Value |
| --- | --- |
| Clone a Repository | Enabled |
| Clone URL | `https://github.com/four-teen/oracle2026.git` |
| Repository Path | `repositories/oracle` relative to the cPanel home directory |
| Repository Name | `Oracle Production` |
| Website directory | `/home/nshft34/public_html` |

The GitHub repository is public, so this HTTPS clone does not require an SSH deploy key.
Select the intended production branch under **Manage → Basic Information → Checked-Out Branch**.
The deployment files must be committed and pushed to that branch before deployment.

## First deployment

1. Create the repository with the settings above. Keep the existing files in `public_html`.
2. Check that the existing production `.env` remains in `public_html`. Its production URLs should be:

   ```dotenv
   APP_URL="https://sksu-oracle.com"
   GOOGLE_REDIRECT_URI="https://sksu-oracle.com/auth/google-callback.php"
   ```

   Keep the production database credentials and Google credentials in that existing file.
   Configure the same Google redirect URL in the OAuth client's authorized redirect URIs.

3. If Terminal is available, check the committed package without changing the live site:

   ```bash
   cd ~/repositories/oracle
   /bin/bash deploy/cpanel.sh --check
   ```

4. In **Git Version Control → Manage → Pull or Deploy**, choose **Update from Remote**.
5. Choose **Deploy HEAD Commit** and wait for the deployment result.
6. Open the live homepage and confirm sign-in, research records, and uploaded abstracts.

## Future updates

Commit and push changes to the selected GitHub branch, then use:

**Update from Remote → Deploy HEAD Commit**

Pushing to GitHub alone does not publish the site with this pull deployment setup.
Edit application code locally, then commit and push it. Keep the cPanel clone clean.

## What deployment does

`.cpanel.yml` runs `deploy/cpanel.sh`. The script:

- Packages committed application code and compiled assets using an explicit list of paths.
- Checks every packaged PHP file before changing the website.
- Saves the existing application files in `~/oracle-deployment-backups/` before overwriting them.
- Copies the package into the existing `~/public_html/` directory.
- Preserves the production `.env`, existing `.htaccess`, uploads, and server-only files.
- Does not copy `.git`, deployment scripts, SQL scripts, tests, or development source folders.
- Does not import or reset the database, and does not delete live files.

PHP CLI must be available as `php`. If the host needs a specific PHP executable, set
`ORACLE_PHP_BIN` to its path in the `.cpanel.yml` deployment task before running the script.

This is an in-place deployment. Files removed from Git remain on the server until deliberately
removed after review. Existing development files uploaded earlier are also left in place.
Backups contain application files, not the production database or uploaded manuscripts;
manage those through the hosting provider's normal backup facilities.

## cPanel references

- [Set up deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-deployment/)
- [Deployment requirements and YAML](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)
