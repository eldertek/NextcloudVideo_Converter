## 2.0.0

- Port to Nextcloud 28 through 35 (`IBootstrap`, `@nextcloud/files` v3 and v4 compatibility, modern conversion modal)
- Harden FFmpeg execution using shell-free argument arrays (`proc_open`) and strict Nextcloud user folder path validation
- Register converted files via Nextcloud VFS and storage `Scanner::scanFile` so file size, ETag, and previews update immediately
- Add `mp3` and `m4a` audio extraction formats plus `mkv` and `mov` video outputs
- Add `.vob`, `.ts`, `.mts`, `.m2ts`, `.mkv`, `.mov`, `.avi`, `.webm`, `.flv`, `.wmv`, and `.mpg` input support
- Add asynchronous `BackgroundJob` queue option and `occ video_converter:convert` CLI command

## 1.0.6

- Support for NC 27

## 1.0.5

- Support for NC 24
- Support copy codec

## 1.0.4

- Support for NC 23

## 1.0.3

- Fixed "faststart is not defined"

## 1.0.2

- Support for NC 22
- Add more resolutions (thanks @CWBudde)
- Add faststart movflag for pseudo streaming (thanks @CWBudde)

## 1.0.1

- Support for NC 21

## 1.0.0

- Better handling of files
- Improved performances
- Support group folders
- Support all external storage methods
