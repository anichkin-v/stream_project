(() => {
    'use strict';

    const icons = window.KIDS_PLAYER_ICONS;
    const icon = (name, className) => (icons ? icons.svg(name, className || 'player-ico') : '');

    const formatTime = seconds => {
        if (!Number.isFinite(seconds)) return '0:00';
        const total = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const rest = total % 60;
        return hours > 0
            ? `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
            : `${minutes}:${String(rest).padStart(2, '0')}`;
    };

    const parseVttTime = value => {
        const parts = value.trim().split(':');
        if (parts.length !== 3) return 0;
        return Number(parts[0]) * 3600 + Number(parts[1]) * 60 + Number(parts[2]);
    };

    const qualityTier = height => {
        if (height >= 2160) return '4K';
        if (height >= 1440) return '2K';
        if (height >= 1080) return 'FHD';
        if (height >= 720) return 'HD';
        if (height >= 480) return 'SD';
        return '';
    };

    class KidsPlayer {
        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.video = root.querySelector('video');
            this.playButton = root.querySelector('[data-action="play"]');
            this.centerPlay = root.querySelector('[data-action="center-play"]');
            this.seek = root.querySelector('[data-role="seek"]');
            this.volume = root.querySelector('[data-role="volume"]');
            this.currentTime = root.querySelector('[data-role="current-time"]');
            this.duration = root.querySelector('[data-role="duration"]');
            this.qualityMenu = root.querySelector('[data-role="quality-menu"]');
            this.qualityBadge = root.querySelector('[data-role="quality-badge"]');
            this.muteButton = root.querySelector('[data-action="mute"]');
            this.fullscreenButton = root.querySelector('[data-action="fullscreen"]');
            this.preview = root.querySelector('[data-role="preview"]');
            this.previewImage = this.preview?.querySelector('img');
            this.previewTime = this.preview?.querySelector('span');
            this.errorBox = root.querySelector('[data-role="error"]');
            this.nextOverlay = root.querySelector('[data-role="next-overlay"]');
            this.hls = null;
            this.previewCues = [];
            this.hideTimer = null;
            this.nextTimer = null;
            this.selectedQualityLabel = 'Авто';
            this.nativeHls = this.video.canPlayType('application/vnd.apple.mpegurl') !== '';

            this.root.style.setProperty('--player-accent', config.accentColor || '#ff4e63');
            this.mountStaticIcons();
            this.bindEvents();
            this.loadSource();
            this.loadPreviews();
            this.restoreVolume();
            this.syncPlaybackState();
            this.syncFullscreenIcon();
        }

        mountStaticIcons() {
            this.setIcon(this.playButton, 'play');
            this.setIcon(this.root.querySelector('[data-icon-slot="next"]'), 'ep-next');
            this.setIcon(this.root.querySelector('[data-icon-slot="settings"]'), 'settings');
            this.setIcon(this.muteButton, 'volume');
            this.setIcon(this.fullscreenButton, 'fullscreen');
        }

        setIcon(element, name) {
            if (!element) return;
            element.innerHTML = icon(name);
        }

        bindEvents() {
            this.playButton.addEventListener('click', () => this.togglePlay());
            this.centerPlay.addEventListener('click', () => this.togglePlay());
            this.video.addEventListener('click', () => this.togglePlay());
            this.video.addEventListener('play', () => this.syncPlaybackState());
            this.video.addEventListener('pause', () => this.syncPlaybackState());
            this.video.addEventListener('timeupdate', () => this.syncTimeline());
            this.video.addEventListener('loadedmetadata', () => this.syncTimeline());
            this.video.addEventListener('durationchange', () => this.syncTimeline());
            this.video.addEventListener('volumechange', () => this.syncVolume());
            this.video.addEventListener('waiting', () => this.root.classList.add('is-buffering'));
            this.video.addEventListener('playing', () => this.root.classList.remove('is-buffering'));
            this.video.addEventListener('error', () => this.showError());
            this.video.addEventListener('ended', () => this.startNextCountdown());

            this.seek.addEventListener('input', () => {
                if (Number.isFinite(this.video.duration)) {
                    this.video.currentTime = (Number(this.seek.value) / 1000) * this.video.duration;
                }
            });
            this.seek.addEventListener('pointermove', event => this.showTimelinePreview(event));
            this.seek.addEventListener('pointerleave', () => {
                if (this.preview) this.preview.hidden = true;
            });

            if (this.volume) {
                this.volume.addEventListener('input', () => {
                    this.video.volume = Number(this.volume.value) / 100;
                    this.video.muted = this.video.volume === 0;
                    localStorage.setItem('kids-player-volume', String(this.video.volume));
                });
            }

            if (this.muteButton) {
                this.muteButton.addEventListener('click', () => {
                    this.video.muted = !this.video.muted;
                });
            }

            if (this.fullscreenButton) {
                this.fullscreenButton.addEventListener('click', () => this.toggleFullscreen());
            }

            const qualityButton = this.root.querySelector('[data-action="quality"]');
            if (qualityButton && this.qualityMenu) {
                qualityButton.addEventListener('click', event => {
                    event.stopPropagation();
                    this.qualityMenu.hidden = !this.qualityMenu.hidden;
                });
            }

            this.root.querySelectorAll('[data-action="next"]').forEach(button => {
                button.addEventListener('click', () => this.goNext());
            });
            const cancelNext = this.root.querySelector('[data-action="cancel-next"]');
            if (cancelNext) cancelNext.addEventListener('click', () => this.cancelNext());

            this.root.addEventListener('pointermove', () => this.showControls());
            this.root.addEventListener('pointerleave', () => this.scheduleControlsHide());
            this.root.addEventListener('keydown', event => this.handleKeyboard(event));
            document.addEventListener('click', () => {
                if (this.qualityMenu) this.qualityMenu.hidden = true;
            });
            document.addEventListener('fullscreenchange', () => {
                this.root.classList.toggle('is-fullscreen', document.fullscreenElement === this.root);
                this.syncFullscreenIcon();
            });
        }

        loadSource() {
            if (this.nativeHls) {
                this.video.src = this.config.source;
                this.buildNativeQualityMenu();
                return;
            }

            if (window.Hls && Hls.isSupported()) {
                this.hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: false,
                    capLevelToPlayerSize: true,
                    startLevel: -1,
                });
                this.hls.loadSource(this.config.source);
                this.hls.attachMedia(this.video);
                this.hls.on(Hls.Events.MANIFEST_PARSED, () => this.buildHlsQualityMenu());
                this.hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => {
                    if (this.hls.autoLevelEnabled) {
                        this.setQualityLabel('Авто', this.hls.levels[data.level]?.height || 0);
                    } else {
                        const level = this.hls.levels[data.level];
                        if (level) this.setQualityLabel(`${level.height}p`, level.height);
                    }
                });
                this.hls.on(Hls.Events.ERROR, (_, data) => {
                    if (data.fatal) this.showError();
                });
                return;
            }

            this.showError();
        }

        buildHlsQualityMenu() {
            const unique = new Map();
            this.hls.levels.forEach((level, index) => {
                if (!unique.has(level.height)) unique.set(level.height, index);
            });
            const options = [{label: 'Авто', value: -1, height: 0}];
            [...unique.entries()]
                .sort((a, b) => b[0] - a[0])
                .forEach(([height, index]) => options.push({
                    label: `${height}p`,
                    value: index,
                    height,
                }));
            this.renderQualityOptions(options, option => {
                this.hls.currentLevel = option.value;
                this.setQualityLabel(option.label, option.height);
            });
            const maxHeight = Math.max(0, ...[...unique.keys()]);
            this.setQualityLabel('Авто', maxHeight);
        }

        buildNativeQualityMenu() {
            const options = [{label: 'Авто', value: this.config.source, height: 0}];
            (this.config.qualities || [])
                .slice()
                .sort((a, b) => b.height - a.height)
                .forEach(quality => options.push({
                    label: quality.label,
                    value: quality.source,
                    height: quality.height || Number.parseInt(quality.label, 10) || 0,
                }));
            this.renderQualityOptions(options, option => {
                const time = this.video.currentTime;
                const playing = !this.video.paused;
                this.video.src = option.value;
                this.video.addEventListener('loadedmetadata', () => {
                    this.video.currentTime = Math.min(time, this.video.duration || time);
                    if (playing) this.video.play().catch(() => {});
                }, {once: true});
                this.setQualityLabel(option.label, option.height);
            });
            const maxHeight = Math.max(0, ...options.map(option => option.height || 0));
            this.setQualityLabel('Авто', maxHeight);
        }

        renderQualityOptions(options, onSelect) {
            if (!this.qualityMenu) return;
            this.qualityMenu.innerHTML = '';
            options.forEach(option => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = option.label === 'Авто' ? 'Авто (адаптивно)' : option.label;
                button.classList.toggle('is-active', option.label === this.selectedQualityLabel);
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    onSelect(option);
                    this.qualityMenu.hidden = true;
                    this.qualityMenu.querySelectorAll('button').forEach(item => {
                        item.classList.toggle('is-active', item === button);
                    });
                });
                this.qualityMenu.appendChild(button);
            });
        }

        setQualityLabel(label, height = 0) {
            this.selectedQualityLabel = label;
            if (!this.qualityBadge) return;
            const tier = qualityTier(height);
            if (!tier) {
                this.qualityBadge.hidden = true;
                return;
            }
            this.qualityBadge.textContent = tier;
            this.qualityBadge.hidden = false;
        }

        async loadPreviews() {
            if (!this.config.previewUrl || !this.preview) return;
            try {
                const response = await fetch(this.config.previewUrl, {cache: 'force-cache'});
                if (!response.ok) return;
                const text = await response.text();
                const base = new URL(this.config.previewUrl, window.location.href);
                const blocks = text.replace(/\r/g, '').split('\n\n').slice(1);
                this.previewCues = blocks.map(block => {
                    const lines = block.trim().split('\n');
                    if (lines.length < 2 || !lines[0].includes('-->')) return null;
                    const [start, end] = lines[0].split('-->').map(parseVttTime);
                    return {
                        start,
                        end,
                        image: new URL(lines[1].trim(), base).href,
                    };
                }).filter(Boolean);
            } catch (_) {
                this.previewCues = [];
            }
        }

        showTimelinePreview(event) {
            if (!this.config.showPreview || !this.preview) return;
            if (!Number.isFinite(this.video.duration)) return;
            const rect = this.seek.getBoundingClientRect();
            const ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
            const time = ratio * this.video.duration;
            const cue = this.previewCues.find(item => time >= item.start && time < item.end);
            this.previewTime.textContent = formatTime(time);
            if (cue) {
                this.previewImage.src = cue.image;
                this.previewImage.hidden = false;
            } else {
                this.previewImage.hidden = true;
            }
            this.preview.hidden = false;
            this.preview.style.left = `${ratio * 100}%`;
        }

        togglePlay() {
            if (this.video.paused) {
                this.video.play().catch(() => {});
            } else {
                this.video.pause();
            }
        }

        syncPlaybackState() {
            const playing = !this.video.paused;
            this.root.classList.toggle('is-playing', playing);
            this.setIcon(this.playButton, playing ? 'pause' : 'play');
            this.playButton.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
            if (playing) this.scheduleControlsHide();
            else this.showControls();
        }

        syncTimeline() {
            const duration = this.video.duration;
            const progress = Number.isFinite(duration) && duration > 0
                ? (this.video.currentTime / duration) * 1000
                : 0;
            this.seek.value = String(progress);
            this.seek.style.setProperty('--seek-progress', `${progress / 10}%`);
            this.currentTime.textContent = formatTime(this.video.currentTime);
            this.duration.textContent = formatTime(duration);
        }

        restoreVolume() {
            if (!this.volume) return;
            const savedValue = localStorage.getItem('kids-player-volume');
            const saved = savedValue === null ? Number.NaN : Number(savedValue);
            const configured = Number(this.config.defaultVolume ?? 80) / 100;
            this.video.volume = Number.isFinite(saved) && saved >= 0 && saved <= 1 ? saved : configured;
            this.syncVolume();
        }

        syncVolume() {
            if (!this.volume) return;
            const level = this.video.muted ? 0 : this.video.volume;
            this.volume.value = String(Math.round(level * 100));
            this.root.classList.toggle('is-muted', level === 0);
            let name = 'volume';
            if (level <= 0.02) name = 'volume-mute';
            else if (level < 0.45) name = 'volume-low';
            this.setIcon(this.muteButton, name);
            if (this.muteButton) {
                this.muteButton.setAttribute('aria-label', level === 0 ? 'Включить звук' : 'Выключить звук');
            }
        }

        syncFullscreenIcon() {
            const active = document.fullscreenElement === this.root;
            this.setIcon(this.fullscreenButton, active ? 'fullscreen-exit' : 'fullscreen');
            if (this.fullscreenButton) {
                this.fullscreenButton.setAttribute(
                    'aria-label',
                    active ? 'Выйти из полноэкранного режима' : 'На весь экран',
                );
            }
        }

        async toggleFullscreen() {
            try {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else if (this.root.requestFullscreen) {
                    await this.root.requestFullscreen();
                } else if (this.video.webkitEnterFullscreen) {
                    this.video.webkitEnterFullscreen();
                }
            } catch (_) {
                // Полноэкранный режим может быть запрещён политикой браузера.
            }
        }

        startNextCountdown() {
            if (!this.config.nextUrl || !this.config.autoplayNext || !this.nextOverlay) return;
            let remaining = Number(this.config.nextDelay ?? 5);
            const counter = this.nextOverlay.querySelector('[data-role="next-countdown"]');
            this.nextOverlay.hidden = false;
            counter.textContent = String(remaining);
            this.nextTimer = window.setInterval(() => {
                remaining -= 1;
                counter.textContent = String(Math.max(0, remaining));
                if (remaining <= 0) this.goNext();
            }, 1000);
        }

        cancelNext() {
            clearInterval(this.nextTimer);
            this.nextTimer = null;
            if (this.nextOverlay) this.nextOverlay.hidden = true;
        }

        goNext() {
            if (this.config.nextUrl) window.location.href = this.config.nextUrl;
        }

        showControls() {
            clearTimeout(this.hideTimer);
            this.root.classList.remove('controls-hidden');
            this.scheduleControlsHide();
        }

        scheduleControlsHide() {
            clearTimeout(this.hideTimer);
            if (!this.video.paused) {
                this.hideTimer = window.setTimeout(() => {
                    this.root.classList.add('controls-hidden');
                    if (this.qualityMenu) this.qualityMenu.hidden = true;
                }, 2800);
            }
        }

        handleKeyboard(event) {
            if (['INPUT', 'BUTTON'].includes(document.activeElement?.tagName) && event.key !== 'Escape') return;
            const seekStep = Number(this.config.seekStep ?? 10);
            if (event.code === 'Space' || event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.togglePlay();
            } else if (event.key === 'ArrowLeft') {
                this.video.currentTime = Math.max(0, this.video.currentTime - seekStep);
            } else if (event.key === 'ArrowRight') {
                this.video.currentTime = Math.min(this.video.duration || Infinity, this.video.currentTime + seekStep);
            } else if (event.key.toLowerCase() === 'm') {
                this.video.muted = !this.video.muted;
            } else if (event.key.toLowerCase() === 'f') {
                this.toggleFullscreen();
            } else if (event.key === 'Escape' && this.qualityMenu) {
                this.qualityMenu.hidden = true;
            }
        }

        showError() {
            this.root.classList.remove('is-buffering');
            if (this.errorBox) this.errorBox.hidden = false;
        }
    }

    const root = document.querySelector('[data-kids-player]');
    const configElement = document.getElementById('kids-player-config');
    if (root && configElement) {
        try {
            new KidsPlayer(root, JSON.parse(configElement.textContent));
        } catch (error) {
            console.error('Kids player initialization failed', error);
        }
    }
})();
