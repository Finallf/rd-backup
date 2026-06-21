# Changelog

All notable changes to the ReloadeD Backup plugin will be documented in this file.<br>
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).<br>

---
<br>


## [1.1.2-beta.1](https://github.com/Finallf/rd-backup/compare/v1.1.1...v1.1.2-beta.1) (2026-06-21)

### 🐛 Bug Fixes

* **updater:** drop <br>/--- separators from the changelog modal ([7190191](https://github.com/Finallf/rd-backup/commit/71901912ecb6a251e0eaadcdcd6e8fc9261aa14a))

<br>

---

## [1.1.1](https://github.com/Finallf/rd-backup/compare/v1.1.0...v1.1.1) (2026-06-21)

### 🐛 Bug Fixes

* **restore:** mirror uploads on restore, plus updater and upload-picker polish ([56b1c9b](https://github.com/Finallf/rd-backup/commit/56b1c9b06412a6add8526ab496500526876ac91e))

<br>

---

## [1.1.0](https://github.com/Finallf/rd-backup/compare/v1.0.0...v1.1.0) (2026-06-20)

### ✨ Features

* **api:** expose rdbk_get_last_backup() for last-backup integrations ([1938d82](https://github.com/Finallf/rd-backup/commit/1938d824bd84c3dc2cfd18a958983a7f8233ecb3))
* **build:** SCSS sources, CI JS minify, and uninstall cleanup ([a77c89a](https://github.com/Finallf/rd-backup/commit/a77c89a59c6e348207f4cb9f6b6814728ee93b3c))
* **i18n:** add translation support and ship the pt_BR locale ([74a3029](https://github.com/Finallf/rd-backup/commit/74a3029776cb73037eb364d96f4444fa7ea7c807))

### 🐛 Bug Fixes

* **admin:** tidy the updates card layout and the nginx snippet style ([7fd3391](https://github.com/Finallf/rd-backup/commit/7fd339140a237a5657ab3caeabc60c4cdba29e82))
* **integrity:** never restore — or keep — a corrupt archive ([327325a](https://github.com/Finallf/rd-backup/commit/327325a08e05188d3f0ff3ddc0672492c6bb59aa))

### 📝 Documentation

* add the en-US documentation ([53bd767](https://github.com/Finallf/rd-backup/commit/53bd76723c5c645b418e94c7a53f90537546982f))
* add the en-US documentation and exclude docs/ from the built plugin zip ([613d951](https://github.com/Finallf/rd-backup/commit/613d9511b91e68f9d293136819d20cac310a0f07))

<br>

---

## [1.1.0-beta.4](https://github.com/Finallf/rd-backup/compare/v1.1.0-beta.3...v1.1.0-beta.4) (2026-06-20)

### ✨ Features

* **api:** expose rdbk_get_last_backup() for last-backup integrations ([1938d82](https://github.com/Finallf/rd-backup/commit/1938d824bd84c3dc2cfd18a958983a7f8233ecb3))

### 🐛 Bug Fixes

* **integrity:** never restore — or keep — a corrupt archive ([327325a](https://github.com/Finallf/rd-backup/commit/327325a08e05188d3f0ff3ddc0672492c6bb59aa))

<br>

---

## [1.1.0-beta.3](https://github.com/Finallf/rd-backup/compare/v1.1.0-beta.2...v1.1.0-beta.3) (2026-06-20)

### ✨ Features

* **build:** SCSS sources, CI JS minify, and uninstall cleanup ([a77c89a](https://github.com/Finallf/rd-backup/commit/a77c89a59c6e348207f4cb9f6b6814728ee93b3c))

<br>

---

## [1.1.0-beta.2](https://github.com/Finallf/rd-backup/compare/v1.1.0-beta.1...v1.1.0-beta.2) (2026-06-20)

### 🐛 Bug Fixes

* **admin:** tidy the updates card layout and the nginx snippet style ([7fd3391](https://github.com/Finallf/rd-backup/commit/7fd339140a237a5657ab3caeabc60c4cdba29e82))

<br>

---

## [1.1.0-beta.1](https://github.com/Finallf/rd-backup/compare/v1.0.0...v1.1.0-beta.1) (2026-06-20)

### ✨ Features

* **i18n:** add translation support and ship the pt_BR locale ([74a3029](https://github.com/Finallf/rd-backup/commit/74a3029776cb73037eb364d96f4444fa7ea7c807))

### 📝 Documentation

* add the en-US documentation ([53bd767](https://github.com/Finallf/rd-backup/commit/53bd76723c5c645b418e94c7a53f90537546982f))
* add the en-US documentation and exclude docs/ from the built plugin zip ([613d951](https://github.com/Finallf/rd-backup/commit/613d9511b91e68f9d293136819d20cac310a0f07))

<br>

---

## 1.0.0 (2026-06-20)

### ✨ Features

* **admin:** 1:1 ReloadeD-theme panel UI ([a34d188](https://github.com/Finallf/rd-backup/commit/a34d188201b6014ed7ed60f43d8d567215cb749a))
* **admin:** show the ReloadeD logo in the panel header ([d75413e](https://github.com/Finallf/rd-backup/commit/d75413edd0208e0d5b778068eda58e758a101a72))
* **backup:** full backup archiver — db + uploads + manifest into one .zip ([ad05c2e](https://github.com/Finallf/rd-backup/commit/ad05c2e1605d6af6903a7fc914f3d4ef0b4e15e8))
* **backup:** resumable database dumper (PHP/wpdb) ([4173b02](https://github.com/Finallf/rd-backup/commit/4173b024d6682f5791f1eefc1f4b62e3c0292ce3))
* **backup:** safety-snapshot retention + reset control ([eb63fbd](https://github.com/Finallf/rd-backup/commit/eb63fbdb7b20d72a528bf1060c2041ba21b61c40))
* **core:** plugin scaffold — resumable job engine, admin UI, health-check ([174a50a](https://github.com/Finallf/rd-backup/commit/174a50ac9a12a785dc0fcbc1dea39fdeb178fb49))
* **restore:** apply restore — safety backup + resumable DB import ([063b2d7](https://github.com/Finallf/rd-backup/commit/063b2d703769c9f43d7560e1f11619b9884f4e43))
* **restore:** extract uploads — completes the same-domain restore ([823f113](https://github.com/Finallf/rd-backup/commit/823f1133ba811b5541e8ca96955f198365193539))
* **restore:** read-only preview — validate manifest, integrity, warnings ([1f85dfc](https://github.com/Finallf/rd-backup/commit/1f85dfce65763fa395d54d0f2323278f738e75b5))
* **restore:** serialized-safe search-replace for cross-domain restores ([da2c593](https://github.com/Finallf/rd-backup/commit/da2c593bd4f596ae3389d35123fa6066e1342430))
* **restore:** upload a backup .zip from the admin ([85d0fe7](https://github.com/Finallf/rd-backup/commit/85d0fe77b38aec880bb82d85d8ed80e451b66711))
* **storage:** secure backup store — token names + PHP-only download ([7b9302c](https://github.com/Finallf/rd-backup/commit/7b9302cbbcb379b925f07a9d11989ca38b0f52f0))
* **updater:** GitHub self-updater + Updates card (1:1 with the theme) ([ea20e47](https://github.com/Finallf/rd-backup/commit/ea20e47a0e4018a2a5f4c15f76a0bf3cc30401bb))

### 🐛 Bug Fixes

* **backup:** escape dump values with mysqli_real_escape_string ([ddb1b9f](https://github.com/Finallf/rd-backup/commit/ddb1b9f7da4953d84125e2c998be853e5efa2630))
* **restore:** harden the job loop and surface import failures ([b576fc4](https://github.com/Finallf/rd-backup/commit/b576fc4cbd029af03a68316514008e93e47cca18))
* **restore:** survive the full-database swap (cross-domain restore) ([9db1329](https://github.com/Finallf/rd-backup/commit/9db13299acce66e3708fa1e3a3eddf36ad573260))
* **updater:** pick the highest semver release, not [0] ([16e0add](https://github.com/Finallf/rd-backup/commit/16e0addd64e1c3aadac49f7ad983135e537f7fed))

### ♻️ Code Refactoring

* clean dev scaffolding and rename to ReloadeD Backup ([a890b9c](https://github.com/Finallf/rd-backup/commit/a890b9c9ef06c5e6e3ab6fce4237123b196f549f))

### 📝 Documentation

* **contributor:** contrib-readme-action has updated readme ([bd35a26](https://github.com/Finallf/rd-backup/commit/bd35a26fe984e52e3805b4e5c2e242b8faf511c3))
* **contributor:** contrib-readme-action has updated readme ([9513c6c](https://github.com/Finallf/rd-backup/commit/9513c6c03e6f723e545eb79709730a5070019bb3))
* rewrite the README in the ReloadeD style ([b48ed24](https://github.com/Finallf/rd-backup/commit/b48ed248ebf895a2e5df2606cc21acdc894ba8dc))

<br>

---

## [1.0.0-beta.16](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.15...v1.0.0-beta.16) (2026-06-19)

### ✨ Features

* **admin:** show the ReloadeD logo in the panel header ([d75413e](https://github.com/Finallf/rd-backup/commit/d75413edd0208e0d5b778068eda58e758a101a72))

### 📝 Documentation

* rewrite the README in the ReloadeD style ([b48ed24](https://github.com/Finallf/rd-backup/commit/b48ed248ebf895a2e5df2606cc21acdc894ba8dc))

<br>

---

## [1.0.0-beta.15](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.14...v1.0.0-beta.15) (2026-06-19)

### 🐛 Bug Fixes

* **updater:** pick the highest semver release, not [0] ([16e0add](https://github.com/Finallf/rd-backup/commit/16e0addd64e1c3aadac49f7ad983135e537f7fed))

<br>

---

## [1.0.0-beta.14](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.13...v1.0.0-beta.14) (2026-06-18)

### ✨ Features

* **updater:** GitHub self-updater + Updates card (1:1 with the theme) ([ea20e47](https://github.com/Finallf/rd-backup/commit/ea20e47a0e4018a2a5f4c15f76a0bf3cc30401bb))

<br>

---

## [1.0.0-beta.13](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.12...v1.0.0-beta.13) (2026-06-18)

### ✨ Features

* **restore:** upload a backup .zip from the admin ([85d0fe7](https://github.com/Finallf/rd-backup/commit/85d0fe77b38aec880bb82d85d8ed80e451b66711))

<br>

---

## [1.0.0-beta.12](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.11...v1.0.0-beta.12) (2026-06-18)

### ✨ Features

* **admin:** 1:1 ReloadeD-theme panel UI ([a34d188](https://github.com/Finallf/rd-backup/commit/a34d188201b6014ed7ed60f43d8d567215cb749a))

<br>

---

## [1.0.0-beta.11](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.10...v1.0.0-beta.11) (2026-06-18)

### ✨ Features

* **backup:** safety-snapshot retention + reset control ([eb63fbd](https://github.com/Finallf/rd-backup/commit/eb63fbdb7b20d72a528bf1060c2041ba21b61c40))

<br>

---

## [1.0.0-beta.10](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.9...v1.0.0-beta.10) (2026-06-18)

### 🐛 Bug Fixes

* **restore:** harden the job loop and surface import failures ([b576fc4](https://github.com/Finallf/rd-backup/commit/b576fc4cbd029af03a68316514008e93e47cca18))

### ♻️ Code Refactoring

* clean dev scaffolding and rename to ReloadeD Backup ([a890b9c](https://github.com/Finallf/rd-backup/commit/a890b9c9ef06c5e6e3ab6fce4237123b196f549f))

<br>

---

# Changelog

All notable changes to the RD Backup plugin will be documented in this file.<br>
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).<br>

---
<br>


## [1.0.0-beta.9](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.8...v1.0.0-beta.9) (2026-06-18)

### 🐛 Bug Fixes

* **restore:** survive the full-database swap (cross-domain restore) ([9db1329](https://github.com/Finallf/rd-backup/commit/9db13299acce66e3708fa1e3a3eddf36ad573260))

<br>

---

## [1.0.0-beta.8](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.7...v1.0.0-beta.8) (2026-06-18)

### ✨ Features

* **restore:** serialized-safe search-replace for cross-domain restores ([da2c593](https://github.com/Finallf/rd-backup/commit/da2c593bd4f596ae3389d35123fa6066e1342430))

<br>

---

## [1.0.0-beta.7](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.6...v1.0.0-beta.7) (2026-06-18)

### ✨ Features

* **restore:** extract uploads — completes the same-domain restore ([823f113](https://github.com/Finallf/rd-backup/commit/823f1133ba811b5541e8ca96955f198365193539))

<br>

---

## [1.0.0-beta.6](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.5...v1.0.0-beta.6) (2026-06-18)

### ✨ Features

* **restore:** apply restore — safety backup + resumable DB import ([063b2d7](https://github.com/Finallf/rd-backup/commit/063b2d703769c9f43d7560e1f11619b9884f4e43))

### 🐛 Bug Fixes

* **backup:** escape dump values with mysqli_real_escape_string ([ddb1b9f](https://github.com/Finallf/rd-backup/commit/ddb1b9f7da4953d84125e2c998be853e5efa2630))

<br>

---

## [1.0.0-beta.5](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.4...v1.0.0-beta.5) (2026-06-17)

### ✨ Features

* **restore:** read-only preview — validate manifest, integrity, warnings ([1f85dfc](https://github.com/Finallf/rd-backup/commit/1f85dfce65763fa395d54d0f2323278f738e75b5))

<br>

---

## [1.0.0-beta.4](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.3...v1.0.0-beta.4) (2026-06-17)

### ✨ Features

* **backup:** full backup archiver — db + uploads + manifest into one .zip ([ad05c2e](https://github.com/Finallf/rd-backup/commit/ad05c2e1605d6af6903a7fc914f3d4ef0b4e15e8))

<br>

---

## [1.0.0-beta.3](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.2...v1.0.0-beta.3) (2026-06-17)

### ✨ Features

* **backup:** resumable database dumper (PHP/wpdb) ([4173b02](https://github.com/Finallf/rd-backup/commit/4173b024d6682f5791f1eefc1f4b62e3c0292ce3))

<br>

---

## [1.0.0-beta.2](https://github.com/Finallf/rd-backup/compare/v1.0.0-beta.1...v1.0.0-beta.2) (2026-06-16)

### ✨ Features

* **storage:** secure backup store — token names + PHP-only download ([7b9302c](https://github.com/Finallf/rd-backup/commit/7b9302cbbcb379b925f07a9d11989ca38b0f52f0))

<br>

---

## 1.0.0-beta.1 (2026-06-16)

### ✨ Features

* **core:** plugin scaffold — resumable job engine, admin UI, health-check ([174a50a](https://github.com/Finallf/rd-backup/commit/174a50ac9a12a785dc0fcbc1dea39fdeb178fb49))

### 📝 Documentation

* **contributor:** contrib-readme-action has updated readme ([9513c6c](https://github.com/Finallf/rd-backup/commit/9513c6c03e6f723e545eb79709730a5070019bb3))

<br>

---
