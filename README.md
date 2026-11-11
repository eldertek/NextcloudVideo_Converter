# Video Converter for Nextcloud

Convert video files and extract audio tracks directly from Nextcloud Files or via `occ`.

## Compatibility

* Nextcloud **28 to 35**
* PHP **8.1+**
* Requires `ffmpeg` installed on the Nextcloud host/container

## Features

* **Nextcloud Files integration (NC 28-35)**: context menu action with format, preset, codec, resolution scaling, bitrate, `faststart`, overwrite, and background queue controls.
* **Video output formats**: `mp4` (H.264/AAC), `webm` (VP8/Vorbis), `mkv`, `mov`, `m4v`, `avi`.
* **Audio extraction**: `mp3`, `m4a` (AAC).
* **Supported input formats**: `.mp4`, `.m4v`, `.mov`, `.mkv`, `.webm`, `.avi`, `.vob`, `.ts`, `.mts`, `.m2ts`, `.mpg`, `.mpeg`, `.flv`, `.wmv`, `.3gp`, `.ogv`.
* **Asynchronous queue & CLI**: run conversions via Nextcloud `BackgroundJob` (`async=true`) or from the command line to avoid HTTP timeouts on large videos:
  ```bash
  php occ video_converter:convert <user> /Videos/input.mkv mp4 --preset=fast
  php occ video_converter:convert <user> /Videos/input.mp4 mp3 --abitrate=192
  ```
* **Hardened execution & VFS sync**: executes `ffmpeg` without a shell using argument vectors and registers converted files through Nextcloud's storage scanner so metadata and previews update immediately.

## License

AGPL-3.0-or-later
