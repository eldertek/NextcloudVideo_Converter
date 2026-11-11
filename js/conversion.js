/**
 * Video Converter - Nextcloud 28-35 Files Action & Conversion Modal
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
	'use strict'

	const ACTION_ID = 'video-converter-convert'

	const SUPPORTED_EXTENSIONS = new Set([
		'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'vob',
		'ts', 'mts', 'm2ts', 'mpg', 'mpeg', 'flv', 'wmv',
		'3gp', 'ogv', 'asf', 'divx',
	])

	const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4zm-6.5 11.5v-5l4.5 2.5-4.5 2.5z"/></svg>'

	const FORMAT_OPTIONS = [
		{ value: 'mp4', label: 'MP4 (H.264 / AAC)', category: 'video' },
		{ value: 'webm', label: 'WebM (VP8 / Vorbis)', category: 'video' },
		{ value: 'mkv', label: 'MKV (Matroska)', category: 'video' },
		{ value: 'mov', label: 'MOV (QuickTime)', category: 'video' },
		{ value: 'm4v', label: 'M4V (MPEG-4 Video)', category: 'video' },
		{ value: 'avi', label: 'AVI (MPEG-4 / MP3)', category: 'video' },
		{ value: 'mp3', label: 'MP3 (Extract Audio)', category: 'audio' },
		{ value: 'm4a', label: 'M4A (Extract AAC Audio)', category: 'audio' },
	]

	const PRESET_OPTIONS = [
		'ultrafast', 'superfast', 'veryfast', 'faster',
		'fast', 'medium', 'slow', 'slower', 'veryslow',
	]

	const SCALE_OPTIONS = [
		{ value: '', label: 'Original resolution' },
		{ value: '3840:-2', label: '2160p (4K - 3840px)' },
		{ value: '2560:-2', label: '1440p (QHD - 2560px)' },
		{ value: '1920:-2', label: '1080p (Full HD - 1920px)' },
		{ value: '1280:-2', label: '720p (HD - 1280px)' },
		{ value: '854:-2', label: '480p (SD - 854px)' },
		{ value: '640:-2', label: '360p (640px)' },
	]

	const CODEC_OPTIONS = [
		{ value: 'auto', label: 'Automatic (recommended)' },
		{ value: 'h264', label: 'H.264 (libx264)' },
		{ value: 'h265', label: 'H.265 / HEVC (libx265)' },
		{ value: 'vp8', label: 'VP8 (libvpx)' },
		{ value: 'vp9', label: 'VP9 (libvpx-vp9)' },
		{ value: 'mpeg4', label: 'MPEG-4 Part 2' },
		{ value: 'copy', label: 'Stream copy (no re-encode)' },
	]

	function translate(text) {
		if (typeof window.t === 'function') {
			return window.t('video_converter', text)
		}
		return text
	}

	function resolveBaseUrl(endpoint) {
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl(endpoint)
		}
		return '/index.php' + endpoint
	}

	function resolveRequestToken() {
		if (window.OC && window.OC.requestToken) {
			return window.OC.requestToken
		}
		const head = document.querySelector('head[data-requesttoken]')
		return head ? head.getAttribute('data-requesttoken') : ''
	}

	function showToast(message, isError) {
		if (window.OC && window.OC.Notification && typeof window.OC.Notification.showTemporary === 'function') {
			window.OC.Notification.showTemporary(message)
			return
		}

		let container = document.getElementById('vc-toast-container')
		if (!container) {
			container = document.createElement('div')
			container.id = 'vc-toast-container'
			container.className = 'vc-toast-container'
			document.body.appendChild(container)
		}

		const toast = document.createElement('div')
		toast.className = 'vc-toast' + (isError ? ' vc-toast--error' : ' vc-toast--success')
		toast.textContent = message
		container.appendChild(toast)

		window.setTimeout(function () {
			toast.classList.add('vc-toast--fade')
			window.setTimeout(function () {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast)
				}
			}, 300)
		}, 4500)
	}

	function extractNodes(arg) {
		if (!arg) {
			return []
		}
		if (Array.isArray(arg)) {
			return arg
		}
		if (Array.isArray(arg.nodes)) {
			return arg.nodes
		}
		return [arg]
	}

	function getNodeName(node) {
		if (!node) {
			return ''
		}
		return String(node.basename || node.name || '')
	}

	function getNodePath(node, fallbackDir) {
		if (!node) {
			return ''
		}
		if (typeof node.path === 'string' && node.path.length > 0) {
			return node.path
		}
		const dir = typeof fallbackDir === 'string' && fallbackDir.length > 0
			? fallbackDir
			: (typeof node.dirname === 'string' ? node.dirname : '/')
		const name = getNodeName(node)
		return (dir === '/' ? '' : dir.replace(/\/+$/, '')) + '/' + name
	}

	function isSupportedMediaNode(node) {
		if (!node) {
			return false
		}
		if (node.type && String(node.type).toLowerCase() === 'folder') {
			return false
		}
		const mime = String(node.mime || node.mimetype || '').toLowerCase()
		if (mime === 'httpd/unix-directory') {
			return false
		}
		if (mime.startsWith('video/')) {
			return true
		}
		const name = getNodeName(node)
		const dotIdx = name.lastIndexOf('.')
		if (dotIdx <= 0 || dotIdx === name.length - 1) {
			return false
		}
		const ext = name.slice(dotIdx + 1).toLowerCase()
		return SUPPORTED_EXTENSIONS.has(ext)
	}

	function openConversionModal(sourcePath, sourceName) {
		const existing = document.getElementById('vc-modal-backdrop')
		if (existing && existing.parentNode) {
			existing.parentNode.removeChild(existing)
		}

		const backdrop = document.createElement('div')
		backdrop.id = 'vc-modal-backdrop'
		backdrop.className = 'vc-modal-backdrop'
		backdrop.setAttribute('role', 'dialog')
		backdrop.setAttribute('aria-modal', 'true')
		backdrop.setAttribute('aria-labelledby', 'vc-modal-title')

		const dialog = document.createElement('div')
		dialog.className = 'vc-modal'

		const header = document.createElement('div')
		header.className = 'vc-modal__header'

		const title = document.createElement('h2')
		title.id = 'vc-modal-title'
		title.className = 'vc-modal__title'
		title.textContent = translate('Convert media')

		const subtitle = document.createElement('p')
		subtitle.className = 'vc-modal__subtitle'
		subtitle.textContent = sourceName

		header.appendChild(title)
		header.appendChild(subtitle)

		const form = document.createElement('form')
		form.className = 'vc-modal__form'

		function createSelectField(id, labelText, options, defaultValue) {
			const group = document.createElement('div')
			group.className = 'vc-field'

			const label = document.createElement('label')
			label.htmlFor = id
			label.textContent = translate(labelText)

			const select = document.createElement('select')
			select.id = id
			select.className = 'vc-input'

			options.forEach(function (opt) {
				const optionEl = document.createElement('option')
				const val = typeof opt === 'string' ? opt : opt.value
				const text = typeof opt === 'string' ? opt : opt.label
				optionEl.value = val
				optionEl.textContent = translate(text)
				if (val === defaultValue) {
					optionEl.selected = true
				}
				select.appendChild(optionEl)
			})

			group.appendChild(label)
			group.appendChild(select)
			return { group: group, input: select }
		}

		function createCheckboxField(id, labelText, checked) {
			const group = document.createElement('label')
			group.className = 'vc-checkbox'
			group.htmlFor = id

			const input = document.createElement('input')
			input.type = 'checkbox'
			input.id = id
			input.checked = Boolean(checked)

			const span = document.createElement('span')
			span.textContent = translate(labelText)

			group.appendChild(input)
			group.appendChild(span)
			return { group: group, input: input }
		}

		const formatField = createSelectField('vc-format', 'Output format', FORMAT_OPTIONS, 'mp4')
		const presetField = createSelectField('vc-preset', 'Encoding preset', PRESET_OPTIONS, 'medium')
		const scaleField = createSelectField('vc-scale', 'Video resolution', SCALE_OPTIONS, '')
		const codecField = createSelectField('vc-codec', 'Video codec', CODEC_OPTIONS, 'auto')

		const bitrateGroup = document.createElement('div')
		bitrateGroup.className = 'vc-field-row'

		const vbitrateWrap = document.createElement('div')
		vbitrateWrap.className = 'vc-field'
		const vbitrateLabel = document.createElement('label')
		vbitrateLabel.htmlFor = 'vc-vbitrate'
		vbitrateLabel.textContent = translate('Video bitrate (kbps, optional)')
		const vbitrateInput = document.createElement('input')
		vbitrateInput.id = 'vc-vbitrate'
		vbitrateInput.type = 'number'
		vbitrateInput.min = '64'
		vbitrateInput.max = '200000'
		vbitrateInput.placeholder = translate('Auto')
		vbitrateInput.className = 'vc-input'
		vbitrateWrap.appendChild(vbitrateLabel)
		vbitrateWrap.appendChild(vbitrateInput)

		const abitrateWrap = document.createElement('div')
		abitrateWrap.className = 'vc-field'
		const abitrateLabel = document.createElement('label')
		abitrateLabel.htmlFor = 'vc-abitrate'
		abitrateLabel.textContent = translate('Audio bitrate (kbps)')
		const abitrateInput = document.createElement('input')
		abitrateInput.id = 'vc-abitrate'
		abitrateInput.type = 'number'
		abitrateInput.min = '32'
		abitrateInput.max = '512'
		abitrateInput.value = '192'
		abitrateInput.className = 'vc-input'
		abitrateWrap.appendChild(abitrateLabel)
		abitrateWrap.appendChild(abitrateInput)

		bitrateGroup.appendChild(vbitrateWrap)
		bitrateGroup.appendChild(abitrateWrap)

		const faststartField = createCheckboxField('vc-faststart', 'Enable streaming faststart (MP4 / MOV / M4A)', true)
		const asyncField = createCheckboxField('vc-async', 'Run in background queue (recommended for large videos)', false)
		const overwriteField = createCheckboxField('vc-overwrite', 'Overwrite existing target file if present', false)

		function syncAudioMode() {
			const selected = FORMAT_OPTIONS.find(function (item) {
				return item.value === formatField.input.value
			})
			const isAudio = selected && selected.category === 'audio'
			presetField.group.style.display = isAudio ? 'none' : ''
			scaleField.group.style.display = isAudio ? 'none' : ''
			codecField.group.style.display = isAudio ? 'none' : ''
			vbitrateWrap.style.display = isAudio ? 'none' : ''
		}
		formatField.input.addEventListener('change', syncAudioMode)
		syncAudioMode()

		const statusEl = document.createElement('div')
		statusEl.className = 'vc-modal__status'
		statusEl.hidden = true

		const footer = document.createElement('div')
		footer.className = 'vc-modal__footer'

		const cancelBtn = document.createElement('button')
		cancelBtn.type = 'button'
		cancelBtn.className = 'vc-btn vc-btn--secondary'
		cancelBtn.textContent = translate('Cancel')

		const submitBtn = document.createElement('button')
		submitBtn.type = 'submit'
		submitBtn.className = 'vc-btn vc-btn--primary'
		submitBtn.textContent = translate('Start conversion')

		footer.appendChild(cancelBtn)
		footer.appendChild(submitBtn)

		form.appendChild(formatField.group)
		form.appendChild(presetField.group)
		form.appendChild(scaleField.group)
		form.appendChild(codecField.group)
		form.appendChild(bitrateGroup)
		form.appendChild(faststartField.group)
		form.appendChild(asyncField.group)
		form.appendChild(overwriteField.group)
		form.appendChild(statusEl)
		form.appendChild(footer)

		dialog.appendChild(header)
		dialog.appendChild(form)
		backdrop.appendChild(dialog)
		document.body.appendChild(backdrop)

		function closeModal() {
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop)
			}
		}

		cancelBtn.addEventListener('click', closeModal)
		backdrop.addEventListener('click', function (ev) {
			if (ev.target === backdrop && !submitBtn.disabled) {
				closeModal()
			}
		})

		form.addEventListener('submit', function (ev) {
			ev.preventDefault()
			submitBtn.disabled = true
			cancelBtn.disabled = true
			statusEl.hidden = false
			statusEl.className = 'vc-modal__status'
			statusEl.textContent = asyncField.input.checked
				? translate('Queueing background conversion...')
				: translate('Converting media, please wait...')

			const payload = {
				source: sourcePath,
				format: formatField.input.value,
				preset: presetField.input.value,
				scale: scaleField.input.value,
				codec: codecField.input.value,
				vbitrate: vbitrateInput.value.trim(),
				abitrate: abitrateInput.value.trim() || '192',
				faststart: faststartField.input.checked,
				async: asyncField.input.checked,
				overwrite: overwriteField.input.checked,
			}

			const headers = {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			}
			const token = resolveRequestToken()
			if (token) {
				headers.requesttoken = token
			}

			window.fetch(resolveBaseUrl('/apps/video_converter/api/v1/convert'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify(payload),
			})
				.then(function (resp) {
					return resp.json().then(function (body) {
						return { ok: resp.ok, body: body }
					})
				})
				.then(function (res) {
					if (!res.ok || (res.body.status !== 'success' && res.body.status !== 'queued')) {
						const msg = (res.body && res.body.data && res.body.data.message)
							? res.body.data.message
							: translate('Conversion failed.')
						throw new Error(msg)
					}

					closeModal()
					if (res.body.status === 'queued') {
						showToast(translate('Conversion queued in background.'), false)
					} else {
						const outName = (res.body.data && res.body.data.name) ? res.body.data.name : ''
						showToast(translate('Conversion completed') + (outName ? ' (' + outName + ')' : ''), false)
						if (window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList) {
							window.OCA.Files.App.fileList.reload()
						} else {
							window.dispatchEvent(new CustomEvent('files:node:updated'))
						}
					}
				})
				.catch(function (err) {
					submitBtn.disabled = false
					cancelBtn.disabled = false
					statusEl.hidden = false
					statusEl.className = 'vc-modal__status vc-modal__status--error'
					statusEl.textContent = err && err.message ? err.message : translate('Conversion failed.')
					showToast(statusEl.textContent, true)
				})
		})

		formatField.input.focus()
	}

	function registerVideoConverterAction() {
		const action = {
			id: ACTION_ID,
			displayName: function () {
				return translate('Convert video / audio')
			},
			iconSvgInline: function () {
				return ICON_SVG
			},
			enabled: function (arg) {
				const nodes = extractNodes(arg)
				if (nodes.length !== 1) {
					return false
				}
				return isSupportedMediaNode(nodes[0])
			},
			exec: function (arg, _view, fallbackDir) {
				const nodes = extractNodes(arg)
				const node = nodes[0]
				if (!node) {
					return null
				}
				const dir = (arg && arg.folder && arg.folder.path) ? arg.folder.path : fallbackDir
				const sourcePath = getNodePath(node, dir)
				const sourceName = getNodeName(node)
				openConversionModal(sourcePath, sourceName)
				return null
			},
			order: 25,
		}

		// Nextcloud 33-35 (@nextcloud/files v4.0 scoped registry)
		window._nc_files_scope = window._nc_files_scope || {}
		window._nc_files_scope.v4_0 = window._nc_files_scope.v4_0 || {}
		const v4Scope = window._nc_files_scope.v4_0
		v4Scope.fileActions = v4Scope.fileActions || new Map()
		v4Scope.registry = v4Scope.registry || new EventTarget()
		v4Scope.fileActions.set(action.id, action)
		v4Scope.registry.dispatchEvent(new CustomEvent('register:action', { detail: action }))

		// Nextcloud 28-32 (@nextcloud/files v3.x global array registry)
		window._nc_fileactions = window._nc_fileactions || []
		if (!window._nc_fileactions.some(function (item) { return item && item.id === action.id })) {
			window._nc_fileactions.push(action)
		}
	}

	registerVideoConverterAction()
})()
