<p align="center">
	<a href="https://github.com/Finallf/rd-backup"><img alt="ReloadeD Backup" src="https://raw.githubusercontent.com/Finallf/rd-backup/master/assets/img/logo-rdbk-panel.webp"></a>
</p>

<br>

<p align="center">
	<a href="#-features">Features</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-requirements">Requirements</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-installation">Installation</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-backup-and-restore">Backup & Restore</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-updates">Updates</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-security">Security</a>&nbsp;&nbsp;•&nbsp;
	<a href="#-support-the-project--apoie-o-projeto">Support</a>
</p>

---
## 🖥️ About the project
### A complete, portable backup & restore plugin for <a href="https://wordpress.org"><img alt="WordPress Logo" width="55" src="https://s.w.org/style/images/about/WordPress-logotype-wmark.png"></a> — a full database dump plus the `wp-content/uploads` folder packaged into a single `.zip`, restorable on **any** host. Pure PHP, zero external dependencies. Pairs with the ReloadeD theme but runs standalone with any theme.

<br>

<p align="center">
  <a href="https://github.com/Finallf/rd-backup/releases"><img alt="GitHub release" src="https://img.shields.io/github/v/release/Finallf/rd-backup?include_prereleases&style=plastic&logo=github"></a>
  &nbsp;
  <a href="https://github.com/Finallf/rd-backup/blob/master/LICENSE"><img alt="License" src="https://img.shields.io/github/license/Finallf/rd-backup?style=plastic"></a>
  &nbsp;
  <a href="https://github.com/Finallf/rd-backup/commits"><img alt="Last commit" src="https://img.shields.io/github/last-commit/Finallf/rd-backup?style=plastic"></a>
  &nbsp;
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.0%2B-blue?style=plastic&logo=wordpress&logoColor=white">
  &nbsp;
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=plastic&logo=php&logoColor=white">
  <br>
  <br>
  <a href="https://github.com/Finallf/rd-backup"><img alt="GitHub" src="https://img.shields.io/badge/GitHub-Finallf/rd--backup-blue?style=plastic&logo=github"></a>
  &nbsp;
  <a href="https://github.com/Finallf/rd-backup/stargazers"><img alt="Stars" src="https://img.shields.io/github/stars/Finallf/rd-backup"></a>
</p>

<br>

---
## 🪶 Features
### Full site data:
 - ✅ The entire database (minus regenerable transients) **plus** the uploads folder, in a single `.zip` with a manifest and an integrity hash.

### Restore anywhere:
 - ✅ Domain-safe — a serialized-aware search-replace rewrites the URLs on a cross-domain restore, so a backup from one site comes back correctly on another.

### Resumable engine:
 - ✅ Chunked, time-boxed steps driven from the browser. Big sites won't time out, and a run survives the tab being closed (state lives in a job file, not the database it's restoring).

### Secure store:
 - ✅ Backups live in `wp-content/rd-backup/` — outside the plugin and outside uploads — with random-token filenames, an authenticated PHP-only download handler and an automatic `.htaccess` deny.

### Built-in self-updater:
 - ✅ WordPress detects new releases on GitHub and offers a 1-click update, like any wp.org plugin — with stable and opt-in beta channels. No external dependencies.

### Lean & CSP-safe:
 - ✅ Native `ZipArchive`, no `exec()`/`mysqldump` requirement, no third-party libraries. All presentation in CSS, all behavior in unobtrusive JavaScript — no inline scripts or styles.

<br>

---

## ❓ Why use this plugin?

 - It's the "go-bag" for the worst case: if the server is gone, spin up WordPress **anywhere**, install the plugin, drop in the backup, and the site comes back exactly as it was.

 - Built for hosts where the usual tools don't work — `exec()` disabled, no shell, low limits. The PHP-pure logical dump runs where `mysqldump` can't.

<br>

---
## 📋 Requirements
> [!IMPORTANT]
> ✔️ WordPress `6.0+`  
> ✔️ PHP `8.0+`  
> ✔️ The `ZipArchive` PHP extension (checked on the Health tab).

<br>

---
## 🧰 Installation
&emsp;1 - Download the latest **`rd-backup.zip`** from the [Releases](https://github.com/Finallf/rd-backup/releases) page.

<br>

&emsp;2 - In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**, choose the `.zip`, and click **Install Now**.

<br>

&emsp;3 - Click **Activate**. The plugin lives under **Tools → ReloadeD Backup**.

<br>

> [!NOTE]
> From here on, updates are **automatic** — the plugin checks GitHub and offers a 1-click update on the Plugins screen, just like any other plugin. See [Updates](#-updates).

<br>

---
## 💾 Backup and Restore
Everything happens under **Tools → ReloadeD Backup**.

<br>

🔹 **Backup**  
&emsp;&ensp;&nbsp;- Click **Create backup** to build a complete `.zip` (database + uploads) into the store. The list below shows your archives, with download and delete.

<br>

🔹 **Restore**  
&emsp;&ensp;&nbsp;- Pick a backup and hit **Preview** — it validates the manifest, checks the integrity hash and shows compatibility warnings. Nothing is written.  
&emsp;&ensp;&nbsp;- To apply it, type `RESTORE` to confirm. A **safety snapshot** of the current site is taken first (the last 2 are kept, so you can undo).

<br>

🔹 **Upload**  
&emsp;&ensp;&nbsp;- Bring a `.zip` from another site straight from the admin (up to the server's upload limit). For larger archives, drop the file into the store via **SFTP**.

<br>

> [!WARNING]
> A restore overwrites the current database. It replaces the users table, so **you will be signed out when it finishes** — just log back in. The safety snapshot is your net.

<br>

---
## 🔄 Updates
The plugin checks GitHub Releases every 24h and surfaces the status on the **Health** tab (current/latest version, a **Check for updates** button, and **Update now** when one is available).

<br>

> [!NOTE]
> **Channels:** *stable* follows the latest stable release; the opt-in **Beta channel** switch follows the newest release including prereleases, and auto-promotes back to stable when a newer stable ships.

<br>

---
## 🔐 Security
This plugin was built to be safe by default:

 - 🔒 **Protected store** — random-token filenames + an authenticated PHP-only download handler. A direct URL to a backup never works; an automatic `.htaccess` deny adds defense in depth (with an optional nginx rule shown in the UI for nginx-in-front setups like HestiaCP).
 - 🔒 **Capability + nonce** on every action (`manage_options`), genuine-upload validation (`is_uploaded_file`), and path-safe resolution inside the store.
 - 🔒 **CSP-friendly** — no inline scripts, no inline event handlers, no inline styles, no `eval`.

<br>

---
## ☕ Support the Project / Apoie o Projeto  
If this project has helped you in any way, consider buying me a coffee! Your donation helps keep the updates and documentation current.  

🇧🇷 Se este projeto te ajudou de alguma forma, considere me pagar um café! Sua doação ajuda a manter as atualizações e a documentação.


|                                                                                                 🌎 GitHub Sponsors                                                                                                  |                                     <img src="https://upload.wikimedia.org/wikipedia/commons/5/50/Pix_%28Brazil%29_logo.svg" width="50px" alt="PIX Logo">                                     |                                                                  <img src="https://avatars.githubusercontent.com/u/476675?s=48&v=4" width="15px" alt="PayPal Logo"> PayPal                                                                   |
| :----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------: | :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------: | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------: |
| You can support me through<br>GitHub Sponsors.<br><p></p><a href="https://github.com/sponsors/finallf"><img src="https://img.shields.io/badge/Sponsor-GitHub-ea4aaa?style=for-the-badge&logo=github-sponsors"></a> | 🇧🇷 Escaneie o QR Code:<br><a href="https://pag.ae/81FaYZrhJ"><img src="https://raw.githubusercontent.com/finallf/terraria/master/assets/qrcode-pix.webp" width="200px" alt="Pix QR Code"></a> | Click or scan the QR code:<br><a href="https://www.paypal.com/donate/?hosted_button_id=9MS3GZX5KGLP2"><img src="https://raw.githubusercontent.com/finallf/terraria/master/assets/qrcode-paypal.webp" width="200px" alt="PayPal QR Code"></a> |

🇧🇷 Ou utilize a Chave Pix (Copia e Cola):

```
25d1d528-df10-4005-bb28-2acf89706243
```

<br>

---
## 💪 How to contribute to the project

> [!NOTE]
> 1. Fork the project.  
> 2. Create a new branch with your changes:  
> `git checkout -b my-feature`
> 3. Save the changes and create a commit message describing what you did:  
> `git commit -m "feat: my new feature"`
> 4. Send your changes:  
> `git push origin my-feature`

<br>

---
## 🛠️ Technologies

The following tools were used in the construction of the project:

<a href="https://www.php.net"><img alt="PHP" src="https://img.shields.io/badge/PHP-%23777BB4?&style=for-the-badge&logo=php&logoColor=white"></a>
<a href="https://wordpress.org"><img alt="WordPress" src="https://img.shields.io/badge/WordPress-%2321759B?&style=for-the-badge&logo=wordpress&logoColor=white"></a>
<a href="https://developer.mozilla.org/docs/Web/JavaScript"><img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-%23F7DF1E?&style=for-the-badge&logo=javascript&logoColor=black"></a>
<a href="https://www.w3.org/Style/CSS"><img alt="CSS3" src="https://img.shields.io/badge/css3%20-%231572B6.svg?&style=for-the-badge&logo=css3&logoColor=white"/></a>
<a href="https://getcomposer.org"><img alt="Composer" src="https://img.shields.io/badge/Composer-%23885630?&style=for-the-badge&logo=composer&logoColor=white"></a>
<a href="https://github.com/features/actions"><img alt="GitHub Actions" src="https://img.shields.io/badge/GitHub%20Actions-%232088FF?&style=for-the-badge&logo=githubactions&logoColor=white"></a>

<br>

---
## 🧑‍💻 Collaborators:
💜 Thank you to everyone who contributed to the improvement of this project 😊

<p align="center">
<!-- readme: collaborators,contributors -start -->
<table>
	<tbody>
		<tr>
            <td align="center">
                <a href="https://github.com/Finallf">
                    <img src="https://avatars.githubusercontent.com/u/8967685?v=4" width="80;" alt="Finallf"/>
                    <br />
                    <sub><b>Finallf</b></sub>
                </a>
            </td>
		</tr>
	<tbody>
</table>
<!-- readme: collaborators,contributors -end -->
</p>

<br>

---
## 🧙‍♂️ Author:
<p align="center">
      <a href="https://reloaded.com.br"><img alt="Finallf" width="100" src="https://avatars.githubusercontent.com/u/8967685"></a>
      <br>
      <br>
      <a href="mailto:finallf@gmail.com"><img alt="Gmail" src="https://img.shields.io/badge/-finallf@gmail.com-c14438?style=plastic&logo=gmail&logoColor=white"></a>
      &nbsp;
      <a href="https://x.com/ReloadeDtec"><img alt="Twitter" src="https://img.shields.io/badge/@ReloadeDtec-blue?style=plastic&logo=X"></a>
      &nbsp;
      <a href="https://forum.reloaded.com.br"><img alt="Static Badge" src="https://img.shields.io/badge/Forum-ReloadeD-blue?style=plastic&logo=phpbb"></a>
      &nbsp;
      <a href="https://discord.gg/HxmqAEkY"><img alt="Static Badge" src="https://img.shields.io/badge/Discord-Finallf-purple?style=plastic&logo=discord"></a>
</p>

<br>

---
## 📝 License:
> [!WARNING]
> This project is licensed under: <a href="https://github.com/Finallf/rd-backup?tab=GPL-2.0-1-ov-file">GPL-2.0 license</a>.
