
(function() {
    const input = document.getElementById('video-file-input');
    const label = document.getElementById('file-label');
    const preview = document.getElementById('file-preview');
    const fileName = document.getElementById('file-name');
    const fileSize = document.getElementById('file-size');
    const uploadInlinePanel = document.getElementById('upload-inline-panel');
    const uploadForm = document.getElementById('video-upload-form');
    const submitButton = document.getElementById('upload-submit-button');
    const uploadStatus = document.getElementById('upload-form-status');
    const uploadProgressPanel = document.getElementById('upload-progress-panel');
    const uploadProgressLabel = document.getElementById('upload-progress-label');
    const uploadProgressPercent = document.getElementById('upload-progress-percent');
    const uploadProgressBar = document.getElementById('upload-progress-bar');
    const aiWorkerShell = document.getElementById('ai-worker-shell');
    const uploadSubmitLabel = document.getElementById('upload-submit-label');
    const runAiInput = document.getElementById('run-ai-input');
    const aiWorkerPanel = document.querySelector('[data-ai-worker-panel]');
    const aiWorkerBadge = document.querySelector('[data-ai-worker-badge]');
    const aiWorkerMessage = document.querySelector('[data-ai-worker-message]');
    const aiWorkerDetail = document.querySelector('[data-ai-worker-detail]');
    const aiWorkerStart = document.querySelector('[data-ai-worker-start]');
    const aiWorkerStartMode = document.getElementById('ai-worker-start-mode');
    const aiWorkerInstanceSelect = document.getElementById('ai-worker-instance-select');
    const aiInstanceInput = document.getElementById('ai-instance-input');
    const aiWorkerLoadingOverlay = document.getElementById('ai-worker-loading-overlay');
    const aiWorkerLoadingTitle = document.getElementById('ai-worker-loading-title');
    const aiWorkerLoadingMessage = document.getElementById('ai-worker-loading-message');
    const confirmDialog = document.getElementById('ai-worker-confirm-dialog');
    const confirmTitle = document.getElementById('ai-worker-confirm-title');
    const confirmMessage = document.getElementById('ai-worker-confirm-message');
    const confirmIcon = document.getElementById('ai-worker-confirm-icon');
    const confirmSubmit = document.getElementById('ai-worker-confirm-submit');
    const confirmSubmitIcon = document.getElementById('ai-worker-confirm-submit-icon');
    const confirmSubmitLabel = document.getElementById('ai-worker-confirm-submit-label');
    const confirmCancel = document.getElementById('ai-worker-confirm-cancel');
    const aiSelectedName = document.querySelector('[data-ai-selected-name]');
    const aiSelectedMeta = document.querySelector('[data-ai-selected-meta]');
    const aiSelectedBadge = document.querySelector('[data-ai-selected-badge]');
    const aiSelectedMessage = document.querySelector('[data-ai-selected-message]');
    const aiRunningIndicator = document.querySelector('[data-ai-running-indicator]');
    const aiRunningName = document.querySelector('[data-ai-running-name]');
    const aiRunningMeta = document.querySelector('[data-ai-running-meta]');
    const aiRunningBadge = document.querySelector('[data-ai-running-badge]');
    const aiRunningMessage = document.querySelector('[data-ai-running-message]');
    const videoSourceMode = document.getElementById('video-source-mode');
    const storedVideoHidden = document.getElementById('stored-video-url-hidden');
    const storedVideoPanel = document.getElementById('stored-video-panel');
    const storedVideoSelectedLabel = document.getElementById('stored-video-selected-label');
    const storedVideoSelectedHelp = document.getElementById('stored-video-selected-help');
    const storedVideoPreviewPanel = document.getElementById('stored-video-preview-panel');
    const storedVideoPreviewPlayer = document.getElementById('stored-video-preview-player');
    const storedVideoPreviewSource = document.getElementById('stored-video-preview-source');
    const uploadVideoPreviewPanel = document.getElementById('upload-video-preview-panel');
    const uploadVideoPreviewPlayer = document.getElementById('upload-video-preview-player');
    const videoPickerSearch = document.getElementById('video-picker-search');
    const videoPickerResults = document.getElementById('video-picker-results');
    const videoPickerOptions = Array.from(document.querySelectorAll('.video-picker-option'));
    const matchSelect = document.getElementById('match-id-select');
    const topnavAiSlot = document.getElementById('page-topnav-status-slot');
    const csrfToken = "";
    const initialAiWorkerData = null;
    const matchVideos = [];
        if ($matchId <= 0) {
            return $carry;
        }

        $videoUrl = trim((string)($match['video_url'] ?? ''));
        $carry[(string)$matchId] = [
            'video_url' => $videoUrl,
            'has_video' => $videoUrl !== '',
            'label' => '#' . $matchId . ' â€¢ ' . trim((string)($match['challanger'] ?? 'TBD') . ' vs ' . (string)($match['opponent'] ?? 'TBD')),
        ];

        return $carry;
    }, []), JSON_UNESCAPED_SLASHES) : '{}' ;
    let aiWorkerState = aiWorkerPanel ? String(aiWorkerPanel.dataset.state || 'unconfigured') : 'unconfigured';
    let confirmAction = null;
    let aiActionTimeout = null;
    let aiActionPending = false;
    let aiWorkerBusyState = false;
    let aiWorkerBusyLabel = 'Checking';
    let latestAiPayload = null;
    let uploadPreviewObjectUrl = null;

    const matchCards = Array.from(document.querySelectorAll('[data-match-card]'));
    const matchEmptyState = document.getElementById('match-selection-empty');

    function selectedWorkerInstance() {
        return aiWorkerInstanceSelect && aiWorkerInstanceSelect.value
            ? aiWorkerInstanceSelect.value
            : (aiInstanceInput ? aiInstanceInput.value : '');
    }

    function setSelectedWorkerInstance(value) {
        if (aiWorkerInstanceSelect && value) {
            aiWorkerInstanceSelect.value = value;
        }
        if (aiInstanceInput) {
            aiInstanceInput.value = value || '';
        }
    }

    function setAiWorkerBusy(isBusy, title, message) {
        aiWorkerBusyState = isBusy;
        aiWorkerBusyLabel = title || 'Checking';
        if (aiWorkerLoadingOverlay) {
            aiWorkerLoadingOverlay.classList.toggle('hidden', !isBusy);
        }
        if (aiWorkerLoadingTitle && title) {
            aiWorkerLoadingTitle.textContent = title;
        }
        if (aiWorkerLoadingMessage && message) {
            aiWorkerLoadingMessage.textContent = message;
        }
        if (aiWorkerInstanceSelect) {
            aiWorkerInstanceSelect.disabled = isBusy;
        }
        if (aiWorkerStart) {
            const baseDisabled = aiWorkerStart.dataset.baseDisabled === 'true';
            aiWorkerStart.disabled = isBusy || baseDisabled;
        }
        const topnavSelect = currentTopnavSelect();
        const topnavActionButton = currentTopnavActionButton();
        const topnavRoot = document.querySelector('[data-ai-worker-topnav-root]');
        const topnavSpinner = document.querySelector('[data-ai-worker-topnav-spinner]');
        const topnavBusyLabel = document.querySelector('[data-ai-worker-topnav-busy-label]');
        const topnavStatus = document.querySelector('[data-ai-worker-topnav-status]');
        if (topnavSelect) {
            topnavSelect.disabled = isBusy || topnavSelect.dataset.baseDisabled === 'true';
        }
        if (topnavActionButton) {
            const baseDisabled = topnavActionButton.dataset.baseDisabled === 'true';
            topnavActionButton.disabled = isBusy || baseDisabled;
            topnavActionButton.classList.toggle('opacity-60', topnavActionButton.disabled);
            topnavActionButton.classList.toggle('cursor-not-allowed', topnavActionButton.disabled);
        }
        if (topnavRoot) {
            topnavRoot.classList.toggle('opacity-80', isBusy);
            topnavRoot.classList.toggle('pointer-events-none', isBusy);
        }
        if (topnavSpinner) {
            topnavSpinner.classList.toggle('hidden', !isBusy);
        }
        if (topnavBusyLabel) {
            topnavBusyLabel.textContent = isBusy ? aiWorkerBusyLabel : '';
        }
        if (topnavStatus && isBusy) {
            topnavStatus.textContent = aiWorkerBusyLabel;
        } else if (!isBusy && latestAiPayload) {
            setAiWorkerState(latestAiPayload);
        }
    }

    function currentTopnavSelect() {
        return document.querySelector('[data-ai-worker-select-topnav]');
    }

    function currentTopnavActionButton() {
        return document.querySelector('[data-ai-worker-action-topnav]');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function workerStateClasses(state) {
        const normalized = String(state || '').toLowerCase();
        if (normalized === 'ready') {
            return 'bg-emerald-500/15 text-emerald-200';
        }
        if (normalized === 'unavailable' || normalized === 'error') {
            return 'bg-rose-500/15 text-rose-200';
        }
        if (normalized === 'starting' || normalized === 'stopping') {
            return 'bg-amber-500/15 text-amber-100';
        }
        return 'bg-white/10 text-white/85';
    }

    function setAiActionPending(isPending) {
        aiActionPending = isPending;
        const actionButton = currentTopnavActionButton();
        [aiWorkerStart, actionButton].forEach((button) => {
            if (!button) {
                return;
            }
            button.disabled = isPending || button.dataset.baseDisabled === 'true';
            button.classList.toggle('opacity-70', button.disabled);
            button.classList.toggle('cursor-not-allowed', button.disabled);
            const spinner = button.querySelector('.ai-action-spinner');
            if (spinner) {
                spinner.classList.toggle('hidden', !isPending);
            }
        });
        if (topnavAiSlot) {
            topnavAiSlot.classList.toggle('pointer-events-none', isPending);
            topnavAiSlot.classList.toggle('opacity-80', isPending);
        }
    }

    function renderTopnavWorker(payload, worker, state) {
        if (!topnavAiSlot) {
            return;
        }

        if (!payload || typeof payload !== 'object') {
            payload = {
                label: 'AI Worker',
                state: 'unconfigured',
                message: 'Not configured'
            };
        }

        try {
            const workersMap = (payload.all_workers && typeof payload.all_workers === 'object') ? payload.all_workers : {};
            const workerEntries = Object.entries(workersMap);
            const normalizedState = String(state || payload.state || '').toLowerCase();
            const payloadState = String(payload.state || '').toLowerCase();
            const hasRunningWorker = !!worker && ['ready', 'starting', 'stopping'].includes(normalizedState);

            const runningKey = hasRunningWorker
                ? String((worker && worker.instance_key) || payload.active_instance_key || '')
                : '';
            const selectedKey = String(
                selectedWorkerInstance()
                || payload.instance_key
                || runningKey
                || (workerEntries.length > 0 ? workerEntries[0][0] : '')
            );
            if (selectedKey !== '') {
                setSelectedWorkerInstance(selectedKey);
            }

            const selectedSummary = (selectedKey !== '' && workersMap[selectedKey] && typeof workersMap[selectedKey] === 'object')
                ? workersMap[selectedKey]
                : payload;
            const workerLabel = hasRunningWorker
                ? String(worker.instance_label || worker.instance_key || selectedSummary.instance_label || 'AI Worker')
                : String(selectedSummary.instance_label || selectedSummary.instance_key || payload.instance_label || 'AI Worker');
            const compactWorkerLabel = workerLabel.split(' â€¢ ')[0];
            const costPerHour = hasRunningWorker
                ? worker.cost_per_hr
                : (selectedSummary.cost_per_hr !== undefined ? selectedSummary.cost_per_hr : payload.cost_per_hr);
            const costLabel = (costPerHour !== null && costPerHour !== undefined)
                ? ('$' + Number(costPerHour).toFixed(2) + '/hr')
                : '';
            const uptimeLabel = hasRunningWorker ? String(worker.uptime_label || '') : '';

            const statusLabel = (() => {
                if (aiWorkerBusyState) {
                    return aiWorkerBusyLabel;
                }
                if (hasRunningWorker) {
                    if (normalizedState === 'ready') {
                        return 'Ready';
                    }
                    if (normalizedState === 'stopping') {
                        return 'Stopping';
                    }
                    return 'Starting';
                }
                if (payloadState === 'unavailable') {
                    return 'No GPU Available';
                }
                if (payloadState === 'error') {
                    return 'Error';
                }
                if (payloadState === 'ready') {
                    return 'Ready';
                }
                if (payloadState === 'starting') {
                    return 'Starting';
                }
                return 'Stopped';
            })();

            const statusClasses = workerStateClasses(hasRunningWorker ? normalizedState : payloadState);
            const hasMultipleWorkers = workerEntries.length > 1;
            const isMigrationSelection = hasRunningWorker && selectedKey !== '' && runningKey !== '' && selectedKey !== runningKey;
            const actionType = isMigrationSelection ? 'migrate' : (hasRunningWorker ? 'stop' : 'start');
            const actionLabel = actionType === 'migrate'
                ? 'Migrate'
                : (actionType === 'stop' ? 'Stop' : (payloadState === 'unavailable' ? 'Retry' : 'Start'));
            const actionIcon = actionType === 'migrate'
                ? 'swap_horiz'
                : (actionType === 'stop' ? 'stop_circle' : 'play_circle');
            const actionDisabled = actionType === 'migrate'
                ? (selectedKey === '' || runningKey === '' || selectedKey === runningKey || (worker && worker.supports_stop === false) || normalizedState === 'stopping')
                : (actionType === 'stop'
                    ? ((worker && worker.supports_stop === false) || normalizedState === 'stopping')
                    : (selectedKey === '' || ['starting', 'stopping', 'unconfigured'].includes(payloadState)));
            const actionClass = actionType === 'migrate'
                ? 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-100'
                : (actionType === 'stop'
                    ? 'bg-rose-500/20 hover:bg-rose-500/30 text-rose-200'
                    : 'bg-primary/20 hover:bg-primary/30 text-primary');
            const optionsHtml = workerEntries.map(function(entry) {
                const key = String(entry[0]);
                const summary = entry[1] && typeof entry[1] === 'object' ? entry[1] : {};
                const optionName = String(summary.instance_label || summary.instance_key || key);
                const optionCost = summary.cost_per_hr !== null && summary.cost_per_hr !== undefined
                    ? (' â€¢ $' + Number(summary.cost_per_hr).toFixed(2) + '/hr')
                    : '';
                const selectedAttr = key === selectedKey ? ' selected' : '';
                return '<option value="' + escapeHtml(key) + '"' + selectedAttr + '>' + escapeHtml(optionName + optionCost) + '</option>';
            }).join('');

            topnavAiSlot.classList.remove('hidden');
            topnavAiSlot.innerHTML = `
                <div data-ai-worker-topnav-root class="inline-flex items-center gap-3 px-3 py-2 rounded-lg" style="background-color:rgba(22,49,34,0.5);border:1px solid rgba(49,87,67,0.3);">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-semibold text-white">${escapeHtml(compactWorkerLabel)}</span>
                        <span data-ai-worker-topnav-spinner class="${aiWorkerBusyState ? '' : 'hidden '}h-3 w-3 rounded-full border-2 border-primary/30 border-t-primary animate-spin"></span>
                        <span data-ai-worker-topnav-status class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ${statusClasses}" style="border:none;">
                            ${escapeHtml(statusLabel)}
                        </span>
                        ${uptimeLabel ? `<span class="text-white/70">â€¢</span><span class="text-white/70">${escapeHtml(uptimeLabel)}</span>` : ''}
                        ${costLabel ? `<span class="text-white/70">â€¢</span><span class="text-primary font-semibold">${escapeHtml(costLabel)}</span>` : ''}
                    </div>
                    ${hasMultipleWorkers ? `
                    <select
                        data-ai-worker-select-topnav
                        data-base-disabled="${['starting', 'stopping'].includes(payloadState) ? 'true' : 'false'}"
                        class="block rounded-lg px-2.5 py-1 text-xs font-semibold bg-[#102719] text-white border border-[#315743] outline-none"
                        aria-label="Select AI worker"
                    >
                        ${optionsHtml}
                    </select>
                    ` : ''}
                    <button
                        type="button"
                        data-ai-worker-action-topnav
                        data-action="${escapeHtml(actionType)}"
                        data-base-disabled="${actionDisabled ? 'true' : 'false'}"
                        class="inline-flex items-center justify-center rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors ${actionClass}"
                        title="${escapeHtml(actionLabel)} GPU worker"
                        aria-label="${escapeHtml(actionLabel)} GPU worker"
                        style="border:none;"
                    >
                        <span class="material-symbols-outlined text-sm">${escapeHtml(actionIcon)}</span>
                        <span class="hidden sm:inline ml-1">${escapeHtml(actionLabel)}</span>
                        <span class="ai-action-spinner hidden h-3 w-3 rounded-full border-2 border-white/70 border-t-transparent animate-spin ml-1"></span>
                    </button>
                </div>
            `;

            const topnavSelect = currentTopnavSelect();
            if (topnavSelect) {
                topnavSelect.value = selectedKey;
                topnavSelect.disabled = aiActionPending || topnavSelect.dataset.baseDisabled === 'true';
                topnavSelect.classList.toggle('opacity-70', topnavSelect.disabled);
                topnavSelect.classList.toggle('cursor-not-allowed', topnavSelect.disabled);
                topnavSelect.addEventListener('change', async function() {
                    const nextInstance = String(topnavSelect.value || '');
                    setSelectedWorkerInstance(nextInstance);
                    setAiWorkerBusy(true, 'Checking selected worker', 'Refreshing status, price, and availability for the selected GPU.');
                    try {
                        await Promise.race([
                            refreshAiWorkerStatus(nextInstance),
                            new Promise((resolve) => window.setTimeout(resolve, 12000))
                        ]);
                    } finally {
                        setAiWorkerBusy(false);
                    }
                });
            }

            const actionButton = currentTopnavActionButton();
            if (!actionButton) {
                return;
            }

            actionButton.disabled = aiActionPending || actionDisabled;
            actionButton.classList.toggle('opacity-70', actionButton.disabled);
            actionButton.classList.toggle('cursor-not-allowed', actionButton.disabled);

            actionButton.addEventListener('click', function() {
                if (actionButton.disabled) {
                    return;
                }

                if (actionType === 'stop') {
                    const instanceKey = runningKey || String((worker && worker.instance_key) || selectedWorkerInstance() || payload.instance_key || '');
                    openConfirmDialog({
                        title: 'Stop AI worker?',
                        message: 'Stop ' + workerLabel + ' now? New AI processing will wait until a worker is started again.',
                        icon: 'stop_circle',
                        confirmLabel: 'Stop GPU',
                        buttonClass: 'bg-[#102719] hover:bg-[#0b1d13]',
                        onConfirm: function() {
                            mutateAiWorker('/video-upload/ai/stop', instanceKey, {
                                busyTitle: 'Stopping GPU worker',
                                busyMessage: 'We are asking Runpod to stop the running worker now.'
                            });
                        }
                    });
                    return;
                }

                if (actionType === 'migrate') {
                    const targetKey = String(selectedKey || selectedWorkerInstance() || '');
                    const targetName = String(selectedSummary.instance_label || selectedSummary.instance_key || targetKey || 'selected worker');
                    openConfirmDialog({
                        title: 'Migrate AI worker?',
                        message: 'Stop ' + workerLabel + ' and start ' + targetName + '?',
                        icon: 'swap_horiz',
                        confirmLabel: 'Migrate',
                        buttonClass: 'bg-amber-600 hover:bg-amber-500',
                        onConfirm: async function() {
                            await mutateAiWorker('/video-upload/ai/stop', runningKey, {
                                busyTitle: 'Stopping current GPU worker',
                                busyMessage: 'Stopping the running worker before starting the selected one.'
                            });
                            window.setTimeout(function() {
                                mutateAiWorker('/video-upload/ai/start', targetKey, {
                                    busyTitle: 'Migrating GPU worker',
                                    busyMessage: 'Starting the selected worker now.'
                                });
                            }, 1200);
                        }
                    });
                    return;
                }

                const instanceKey = String(selectedKey || payload.instance_key || selectedWorkerInstance() || '');
                openConfirmDialog({
                    title: 'Start AI worker?',
                    message: 'Start ' + workerLabel + ' now? Billing begins while the GPU pod is running.',
                    icon: 'play_circle',
                    confirmLabel: 'Start GPU',
                    buttonClass: 'bg-primary hover:bg-green-600',
                    onConfirm: function() {
                        mutateAiWorker('/video-upload/ai/start', instanceKey, {
                            busyTitle: 'Starting GPU worker',
                            busyMessage: 'Runpod is spinning up the selected GPU. This can take a little while.'
                        });
                    }
                });
            });
        } catch (e) {
            console.error('Error rendering topnav worker:', e);
        }
    }

    function setAiWorkerState(payload) {
        if (!aiWorkerPanel || !payload) {
            return;
        }

        latestAiPayload = payload;
        const state = String(payload.state || 'unknown').toLowerCase();
        aiWorkerState = state;
        aiWorkerPanel.dataset.state = state;
        const activeKey = payload.active_instance_key ? String(payload.active_instance_key) : '';
        const payloadKey = payload.instance_key ? String(payload.instance_key) : '';
        const selectedKey = selectedWorkerInstance();
        const workerKey = activeKey || payloadKey || selectedKey;
        const rawWorker = payload.active_worker
            || (payload.all_workers && workerKey ? payload.all_workers[workerKey] : null)
            || null;
        const runningWorker = rawWorker || (['ready', 'starting', 'stopping'].includes(state) ? {
            instance_key: workerKey,
            instance_label: payload.instance_label || payload.label || 'AI Worker',
            label: payload.label || 'AI Worker',
            gpu_name: payload.gpu_name || 'Unknown GPU',
            uptime_label: payload.uptime_label || (state === 'starting' ? 'Starting now' : ''),
            cost_per_hr: payload.cost_per_hr,
            supports_stop: payload.supports_stop,
            state: state
        } : null);
        const runningState = runningWorker ? String(runningWorker.state || state) : state;
        const hasRunningWorker = ['ready', 'starting', 'stopping'].includes(runningState.toLowerCase());
        const selectedOption = aiWorkerInstanceSelect ? aiWorkerInstanceSelect.selectedOptions[0] : null;
        const selectedWorkerSummary = (payload.all_workers && selectedKey && payload.all_workers[selectedKey])
            ? payload.all_workers[selectedKey]
            : (payload.all_workers && workerKey && payload.all_workers[workerKey] ? payload.all_workers[workerKey] : null);
        const selectedWorkerName = selectedOption
            ? selectedOption.textContent.trim()
            : String(
                (selectedWorkerSummary && (selectedWorkerSummary.instance_label || selectedWorkerSummary.instance_key))
                || payload.instance_label
                || payload.instance_key
                || workerKey
                || 'Selected worker'
            );

        if (aiWorkerBadge) {
            aiWorkerBadge.textContent = String(payload.label || 'Unknown');
            aiWorkerBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiWorkerBadge.classList.add(...workerStateClasses(state).split(' '));
        }

        if (aiWorkerMessage) {
            aiWorkerMessage.textContent = String(payload.message || '');
        }
        if (aiSelectedName) {
            aiSelectedName.textContent = selectedWorkerName;
        }
        if (aiSelectedMeta) {
            const metaParts = [];
            const selectedGpuName = selectedWorkerSummary && selectedWorkerSummary.gpu_name
                ? selectedWorkerSummary.gpu_name
                : payload.gpu_name;
            const selectedCostPerHour = selectedWorkerSummary && selectedWorkerSummary.cost_per_hr !== undefined
                ? selectedWorkerSummary.cost_per_hr
                : payload.cost_per_hr;
            if (selectedGpuName) {
                metaParts.push(String(selectedGpuName));
            }
            if (selectedCostPerHour !== null && selectedCostPerHour !== undefined) {
                metaParts.push('$' + Number(selectedCostPerHour).toFixed(2) + '/hr');
            }
            aiSelectedMeta.textContent = metaParts.length > 0 ? metaParts.join(' â€¢ ') : 'Worker details unavailable';
        }
        if (aiSelectedBadge) {
            aiSelectedBadge.textContent = String(payload.label || 'Unknown');
            aiSelectedBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiSelectedBadge.classList.add(...workerStateClasses(state).split(' '));
        }
        if (aiSelectedMessage) {
            let selectedMessage = String(payload.message || '');
            if (hasRunningWorker && runningWorker && String(runningWorker.instance_key || '') !== String(workerKey || '')) {
                selectedMessage = selectedWorkerName + ' is selected, but ' + String(runningWorker.instance_label || runningWorker.label || 'another worker') + ' is currently running.';
            }
            aiSelectedMessage.textContent = selectedMessage;
        }
        if (aiWorkerDetail) {
            if (payload.has_network_volume) {
                aiWorkerDetail.textContent = 'This pod has a network volume attached, so Runpod only allows terminate-style shutdowns, not a normal stop.';
            } else if (state === 'unavailable') {
                aiWorkerDetail.textContent = 'Runpod could not find a free GPU for this worker on the last start attempt. Try another worker or retry later.';
            } else if (payload.active_job_count > 0) {
                aiWorkerDetail.textContent = 'This worker is busy with ' + payload.active_job_count + ' AI job(s). It will auto-stop after 30 minutes of inactivity.';
            } else if (payload.idle_limit_seconds) {
                aiWorkerDetail.textContent = 'This worker auto-stops after ' + Math.round(Number(payload.idle_limit_seconds) / 60) + ' minutes of inactivity.';
            } else if (payload.last_status_change) {
                aiWorkerDetail.textContent = 'Last update: ' + String(payload.last_status_change);
            } else {
                aiWorkerDetail.textContent = 'The upload form will only send video files once the worker reaches Ready.';
            }
        }

        if (hasRunningWorker) {
            setSelectedWorkerInstance(String(runningWorker.instance_key || workerKey || ''));
        }

        if (aiRunningIndicator) {
            aiRunningIndicator.classList.toggle('hidden', !hasRunningWorker);
        }
        if (aiRunningName) {
            aiRunningName.textContent = hasRunningWorker
                ? String(runningWorker.instance_label || runningWorker.label || 'AI Worker')
                : 'No worker running';
        }
        if (aiRunningMeta) {
            const runningParts = [];
            if (hasRunningWorker && runningWorker.gpu_name) {
                runningParts.push(String(runningWorker.gpu_name));
            }
            if (hasRunningWorker && runningWorker.uptime_label) {
                runningParts.push(String(runningWorker.uptime_label));
            }
            if (hasRunningWorker && runningWorker.cost_per_hr !== null && runningWorker.cost_per_hr !== undefined) {
                runningParts.push('$' + Number(runningWorker.cost_per_hr).toFixed(2) + '/hr');
            }
            aiRunningMeta.textContent = runningParts.join(' â€¢ ');
        }
        if (aiRunningBadge) {
            aiRunningBadge.textContent = hasRunningWorker
                ? (String(runningState).toLowerCase() === 'starting' ? 'Warming Up' : String(runningState).toLowerCase() === 'stopping' ? 'Stopping' : 'Running')
                : 'Stopped';
            aiRunningBadge.className = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border';
            aiRunningBadge.classList.add(...workerStateClasses(runningState).split(' '));
        }
        if (aiRunningMessage) {
            if (hasRunningWorker && String(runningWorker.instance_key || '') === String(workerKey || '')) {
                aiRunningMessage.textContent = 'This is the worker you selected. You can process videos now or stop it from the top bar when finished.';
            } else if (hasRunningWorker) {
                aiRunningMessage.textContent = 'A different worker is already running. Stop it from the top bar before starting another one.';
            } else {
                aiRunningMessage.textContent = 'No worker is running right now.';
            }
        }

        renderTopnavWorker(payload, runningWorker, runningState);

        if (aiWorkerStart) {
            const startDisabled = aiActionPending || hasRunningWorker || state === 'starting' || state === 'stopping' || state === 'unconfigured';
            aiWorkerStart.dataset.baseDisabled = startDisabled ? 'true' : 'false';
            aiWorkerStart.disabled = startDisabled;
            aiWorkerStart.classList.toggle('opacity-60', startDisabled);
            aiWorkerStart.classList.toggle('cursor-not-allowed', startDisabled);
        }
        if (aiWorkerInstanceSelect) {
            aiWorkerInstanceSelect.disabled = aiActionPending || hasRunningWorker || state === 'starting' || state === 'stopping';
        }
    }

    async function refreshAiWorkerStatus(instanceKey = '') {
        if (!aiWorkerPanel) {
            return null;
        }

        try {
            const currentInstance = instanceKey || selectedWorkerInstance();
            const params = currentInstance ? ('?ai_instance=' + encodeURIComponent(currentInstance)) : '';
            const response = await fetch('/video-upload/ai/status' + params, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) {
                return null;
            }

            const payload = await response.json();
            setAiWorkerState(payload);
            if (payload.active_instance_key) {
                setSelectedWorkerInstance(String(payload.active_instance_key));
            }
            return payload;
        } catch (error) {
            // Keep the last rendered state if the status endpoint temporarily fails.
            return null;
        }
    }

    async function mutateAiWorker(endpoint, instanceKey, config = {}) {
        if (!aiWorkerPanel) {
            return;
        }

        try {
            setAiWorkerBusy(true, config.busyTitle || 'Working on GPU worker', config.busyMessage || 'Please wait while we update the selected worker.');
            setAiActionPending(true);
            if (aiActionTimeout) {
                clearTimeout(aiActionTimeout);
            }
            aiActionTimeout = window.setTimeout(function() {
                setAiActionPending(false);
                setAiWorkerBusy(false);
                if (uploadStatus) {
                    uploadStatus.classList.remove('hidden');
                    uploadStatus.textContent = 'The GPU request is taking longer than expected. You can wait, or try again.';
                }
            }, 20000);

            const body = new URLSearchParams({ _csrf: csrfToken });
            if (instanceKey) {
                body.set('ai_instance', instanceKey);
            }
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString(),
                credentials: 'same-origin'
            });

            const payload = await response.json().catch(() => null);
            setAiActionPending(false);
            setAiWorkerBusy(false);
            if (aiActionTimeout) {
                clearTimeout(aiActionTimeout);
            }
            if (!response.ok || !payload || !payload.ok) {
                const error = payload && payload.error ? payload.error : 'The AI worker request could not be completed right now.';
                if (payload && payload.status) {
                    setAiWorkerState(payload.status);
                }
                if (!payload?.status && latestAiPayload) {
                    setAiWorkerState(latestAiPayload);
                }
                if (error.toLowerCase().includes('no gpu is available')) {
                    setAiWorkerState({
                        ...payload,
                        ...(payload && payload.status ? payload.status : {}),
                        state: 'unavailable',
                        label: 'No GPU Available',
                        message: error,
                        supports_stop: false,
                        ai_ready: false
                    });
                }
                if (uploadStatus) {
                    uploadStatus.classList.remove('hidden');
                    uploadStatus.textContent = error;
                }
                return;
            }

            if (payload.status) {
                setAiWorkerState(payload.status);
            }
            if (uploadStatus && payload.message) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = String(payload.message);
            }
            window.setTimeout(refreshAiWorkerStatus, 1200);
        } catch (error) {
            setAiActionPending(false);
            setAiWorkerBusy(false);
            if (latestAiPayload) {
                setAiWorkerState(latestAiPayload);
            }
            if (uploadStatus) {
                uploadStatus.classList.remove('hidden');
                uploadStatus.textContent = 'The AI worker could not be reached right now.';
            }
        }
    }

    function closeConfirmDialog() {
        confirmAction = null;
        if (confirmDialog && confirmDialog.open) {
            confirmDialog.close();
        }
    }

    function openConfirmDialog(config) {
        if (!confirmDialog || !confirmTitle || !confirmMessage || !confirmSubmit || !confirmSubmitLabel || !confirmIcon || !confirmSubmitIcon) {
            return;
        }

        confirmTitle.textContent = config.title;
        confirmMessage.textContent = config.message;
        confirmIcon.textContent = config.icon;
        confirmSubmitIcon.textContent = config.icon;
        confirmSubmitLabel.textContent = config.confirmLabel;
        confirmSubmit.className = 'px-4 py-2.5 rounded-xl text-white text-sm font-bold inline-flex items-center gap-2 ' + config.buttonClass;
        confirmAction = config.onConfirm;
        confirmDialog.showModal();
    }

    if (confirmSubmit) {
        confirmSubmit.addEventListener('click', function() {
            const action = confirmAction;
            closeConfirmDialog();
            if (typeof action === 'function') {
                action();
            }
        });
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', closeConfirmDialog);
    }

    if (aiWorkerInstanceSelect && aiInstanceInput) {
        aiWorkerInstanceSelect.addEventListener('change', async function() {
            const nextInstance = aiWorkerInstanceSelect.value;
            setSelectedWorkerInstance(nextInstance);
            setAiWorkerBusy(true, 'Checking selected worker', 'Refreshing status, price, and availability for the selected GPU.');
            try {
                await Promise.race([
                    refreshAiWorkerStatus(nextInstance),
                    new Promise((resolve) => window.setTimeout(resolve, 12000))
                ]);
            } finally {
                setAiWorkerBusy(false);
            }
        });
    }

    if (aiWorkerStart) {
        aiWorkerStart.addEventListener('click', function() {
            const instanceKey = selectedWorkerInstance();
            const selectedOption = aiWorkerInstanceSelect ? aiWorkerInstanceSelect.selectedOptions[0] : null;
            const workerName = selectedOption ? selectedOption.textContent.trim() : 'the selected AI worker';
            openConfirmDialog({
                title: 'Start AI worker?',
                message: 'Start ' + workerName + ' now? Billing begins while the GPU pod is running.',
                icon: 'play_circle',
                confirmLabel: 'Start GPU',
                buttonClass: 'bg-primary hover:bg-green-600',
                onConfirm: function() {
                    mutateAiWorker('/video-upload/ai/start', instanceKey, {
                        busyTitle: 'Starting GPU worker',
                        busyMessage: 'Runpod is spinning up the selected GPU. This can take a little while.'
                    });
                }
            });
        });
    }

    if (input && label && preview && fileName && fileSize) {
        input.addEventListener('change', function() {
            if (this.files.length > 0) {
                const f = this.files[0];
                label.textContent = 'Video Selected';
                fileName.textContent = f.name;
                const mb = (f.size / (1024 * 1024)).toFixed(1);
                fileSize.textContent = '(' + mb + ' MB)';
                preview.classList.remove('hidden');
                if (uploadPreviewObjectUrl) {
                    URL.revokeObjectURL(uploadPreviewObjectUrl);
                }
                uploadPreviewObjectUrl = URL.createObjectURL(f);
                if (uploadVideoPreviewPlayer && uploadVideoPreviewPanel) {
                    uploadVideoPreviewPlayer.src = uploadPreviewObjectUrl;
                    uploadVideoPreviewPanel.classList.remove('hidden');
                }
            } else {
                label.textContent = 'Upload New Video';
                preview.classList.add('hidden');
                if (uploadPreviewObjectUrl) {
                    URL.revokeObjectURL(uploadPreviewObjectUrl);
                    uploadPreviewObjectUrl = null;
                }
                if (uploadVideoPreviewPlayer && uploadVideoPreviewPanel) {
                    uploadVideoPreviewPlayer.pause();
                    uploadVideoPreviewPlayer.removeAttribute('src');
                    uploadVideoPreviewPlayer.load();
                    uploadVideoPreviewPanel.classList.add('hidden');
                }
            }
        });
    }

    function applyVideoChoice(mode, value, labelText) {
        if (videoSourceMode) {
            videoSourceMode.value = mode;
        }
        if (storedVideoHidden) {
            storedVideoHidden.value = mode === 'stored' ? String(value || '') : '';
        }
        if (videoPickerSearch) {
            videoPickerSearch.value = mode === 'stored'
                ? labelText
                : 'Upload New Video';
        }
        if (storedVideoPreviewPanel) {
            storedVideoPreviewPanel.classList.toggle('hidden', mode !== 'stored' || !value);
        }
        if (storedVideoPreviewPlayer && storedVideoPreviewSource) {
            if (mode === 'stored' && value) {
                storedVideoPreviewSource.src = String(value);
                storedVideoPreviewPlayer.load();
            } else {
                storedVideoPreviewPlayer.pause();
                storedVideoPreviewSource.src = '';
                storedVideoPreviewPlayer.load();
            }
        }
        if (uploadInlinePanel) {
            uploadInlinePanel.classList.toggle('hidden', mode === 'stored');
        }
        if (mode !== 'stored' && input) {
            input.value = '';
            label.textContent = 'Upload New Video';
            preview.classList.add('hidden');
            if (uploadPreviewObjectUrl) {
                URL.revokeObjectURL(uploadPreviewObjectUrl);
                uploadPreviewObjectUrl = null;
            }
            if (uploadVideoPreviewPlayer && uploadVideoPreviewPanel) {
                uploadVideoPreviewPlayer.pause();
                uploadVideoPreviewPlayer.removeAttribute('src');
                uploadVideoPreviewPlayer.load();
                uploadVideoPreviewPanel.classList.add('hidden');
            }
        }
    }

    function filterVideoPickerOptions() {
        if (!videoPickerSearch) {
            return;
        }
        const query = videoPickerSearch.value.trim().toLowerCase();
        const selectedMatch = String(matchSelect?.value || '');
        let visibleCount = 0;
        videoPickerOptions.forEach((option) => {
            const haystack = String(option.dataset.search || option.textContent || '').toLowerCase();
            const optionMatchId = String(option.dataset.matchId || '');
            const matchesSelectedMatch = selectedMatch === '' || optionMatchId === '' || optionMatchId === selectedMatch;
            const show = matchesSelectedMatch && (query === '' || haystack.includes(query));
            option.classList.toggle('hidden', !show);
            if (show) {
                visibleCount += 1;
            }
        });
        videoPickerResults.classList.toggle('hidden', visibleCount === 0);
    }

    if (videoPickerSearch && videoPickerResults) {
        videoPickerSearch.addEventListener('focus', function() {
            if (!videoPickerOptions.length) {
                return;
            }
            videoPickerResults.classList.remove('hidden');
            filterVideoPickerOptions();
        });
        videoPickerSearch.addEventListener('input', function() {
            if (!videoPickerOptions.length) {
                return;
            }
            videoPickerResults.classList.remove('hidden');
            filterVideoPickerOptions();
        });
        document.addEventListener('click', function(event) {
            const pickerRoot = document.getElementById('video-library-picker');
            if (pickerRoot && !pickerRoot.contains(event.target)) {
                videoPickerResults.classList.add('hidden');
            }
        });
        videoPickerOptions.forEach(function(option) {
            option.addEventListener('click', function() {
                const mode = String(option.dataset.mode || 'stored');
                const value = String(option.dataset.value || '');
                const labelText = option.querySelector('.font-medium')?.textContent?.trim() || option.textContent.trim();
                applyVideoChoice(mode, value, labelText);
                videoPickerResults.classList.add('hidden');
            });
        });
        applyVideoChoice('upload', '', 'Upload New Video');
    }

    function updateMatchCards() {
        if (!matchCards.length) {
            return;
        }
        const selected = matchSelect ? String(matchSelect.value || '') : '';
        let shown = 0;
        matchCards.forEach((card) => {
            const cardMatchId = String(card.dataset.matchId || '');
            const show = selected !== '' && cardMatchId === selected;
            card.classList.toggle('hidden', !show);
            if (show) {
                shown += 1;
            }
        });
        if (matchEmptyState) {
            matchEmptyState.classList.toggle('hidden', shown > 0);
        }
    }

    if (matchSelect) {
        matchSelect.addEventListener('change', function() {
            filterVideoPickerOptions();
            if (videoSourceMode && videoSourceMode.value === 'stored') {
                const activeOption = videoPickerOptions.find((option) => String(option.dataset.value || '') === String(storedVideoHidden?.value || ''));
                const activeMatchId = String(activeOption?.dataset.matchId || '');
                if (activeMatchId !== '' && activeMatchId !== String(matchSelect.value || '')) {
                    applyVideoChoice('upload', '', 'Upload New Video');
                }
            }
            updateMatchCards();
        });
        updateMatchCards();
    }

    function setUploadProgress(percent, message, labelText) {
        if (uploadProgressPanel) {
            uploadProgressPanel.classList.remove('hidden');
        }
        if (uploadProgressLabel && labelText) {
            uploadProgressLabel.textContent = labelText;
        }
        if (uploadProgressPercent) {
            uploadProgressPercent.textContent = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (uploadStatus) {
            uploadStatus.classList.remove('hidden');
            uploadStatus.textContent = message;
        }
    }

    function resetUploadButton() {
        submitButton.disabled = false;
        submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        if (uploadSubmitLabel) {
            uploadSubmitLabel.textContent = 'Run AI';
        }
    }

    if (uploadForm && submitButton && window.XMLHttpRequest) {
        uploadForm.addEventListener('submit', function(e) {
            const selectedFile = input.files && input.files.length > 0 ? input.files[0] : null;
            const selectedMode = videoSourceMode ? videoSourceMode.value : 'upload';
            const wantsAi = runAiInput ? runAiInput.value === '1' : true;
            const selectedMatchId = matchSelect ? String(matchSelect.value || '') : '';
            if (!selectedMatchId) {
                e.preventDefault();
                setUploadProgress(0, 'Select a match before running AI.', 'Match Required');
                return;
            }
            if (!selectedFile) {
                if (selectedMode === 'stored' && storedVideoHidden && storedVideoHidden.value.trim() && wantsAi && aiWorkerPanel && aiWorkerState !== 'ready') {
                    e.preventDefault();
                    setUploadProgress(0, 'Start the selected AI worker and wait for Ready before running AI.', 'AI Worker Required');
                    return;
                }
                if (selectedMode === 'upload') {
                    e.preventDefault();
                    setUploadProgress(0, 'Choose a saved video or upload a new video first.', 'Video Required');
                    return;
                }
                submitButton.disabled = true;
                submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                if (uploadSubmitLabel) {
                    uploadSubmitLabel.textContent = wantsAi ? 'Running AI...' : 'Saving...';
                }
                if (uploadStatus) {
                    uploadStatus.classList.remove('hidden');
                    uploadStatus.textContent = wantsAi
                        ? 'Starting AI processing for the selected video...'
                        : 'Saving the video selection now. You will be redirected back here.';
                }
                return;
            }

            if (wantsAi && aiWorkerPanel && aiWorkerState !== 'ready') {
                e.preventDefault();
                setUploadProgress(0, 'Start the AI worker and wait for Ready before running AI.', 'AI Worker Required');
                return;
            }

            e.preventDefault();
            submitButton.disabled = true;
            submitButton.classList.add('opacity-70', 'cursor-not-allowed');
            if (uploadSubmitLabel) {
                uploadSubmitLabel.textContent = wantsAi ? 'Uploading + Running AI...' : 'Uploading Video...';
            }
            setUploadProgress(0, 'Preparing the upload...', 'Uploading Video');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', uploadForm.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.addEventListener('progress', function(event) {
                if (!event.lengthComputable) {
                    setUploadProgress(5, 'Uploading the video...', 'Uploading Video');
                    return;
                }

                const percent = Math.max(1, Math.round((event.loaded / event.total) * 100));
                setUploadProgress(percent, 'Uploading the video to the server...', 'Uploading Video');
            });

            xhr.addEventListener('load', function() {
                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    payload = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                    setUploadProgress(100, 'Upload complete. Redirecting to live AI progress...', 'Upload Complete');
                    window.setTimeout(function() {
                        window.location.href = payload.redirect_url || '/video-upload';
                    }, 400);
                    return;
                }

                const errorMessage = payload && payload.error
                    ? payload.error
                    : 'The upload could not be completed right now. Please try again.';
                setUploadProgress(0, errorMessage, 'Upload Failed');
                resetUploadButton();
            });

            xhr.addEventListener('error', function() {
                setUploadProgress(0, 'The upload failed because the server could not be reached.', 'Upload Failed');
                resetUploadButton();
            });

            xhr.addEventListener('abort', function() {
                setUploadProgress(0, 'The upload was cancelled before it finished.', 'Upload Cancelled');
                resetUploadButton();
            });

            xhr.send(new FormData(uploadForm));
        });
    }

    if (aiWorkerPanel) {
        refreshAiWorkerStatus();
        window.setInterval(function() {
            if (aiWorkerState === 'starting' || aiWorkerState === 'stopping' || aiWorkerState === 'ready' || aiWorkerState === 'unavailable') {
                refreshAiWorkerStatus();
            }
        }, 5000);
    }

    // Initialize topnav AI worker status indicator on page load with PHP data
    (function initializeTopnavStatus() {
        try {
            let payload = (typeof initialAiWorkerData !== 'undefined') ? initialAiWorkerData : null;

            // Provide default fallback if data is missing
            if (!payload || typeof payload !== 'object' || Object.keys(payload).length === 0) {
                payload = {
                    'configured': true,
                    'instance_key': 'rtx2000ada',
                    'state': 'stopped',
                    'label': 'Budget AI Worker (RTX 2000 Ada)',
                    'message': 'Checking worker status...',
                    'supports_stop': false,
                    'ai_ready': false,
                    'cost_per_hr': 0.24
                };
            }

            setAiWorkerState(payload);

            // Then try to refresh with fresh data from the API
            refreshAiWorkerStatus().then(function(status) {
                if (status) {
                    setAiWorkerState(status);
                }
            }).catch(function(e) {
                console.error('Failed to refresh status:', e);
            });
        } catch (e) {
            console.error('Failed to initialize topnav status:', e);
        }
    })();
})();

(function() {
    const panels = Array.from(document.querySelectorAll('[data-ai-progress]'));
    if (!panels.length) {
        return;
    }

    const stageLabel = {
        pending: 'Pending',
        queued: 'Queued',
        processing: 'Processing',
        processed: 'Complete',
        failed: 'Failed',
        startup: 'Preparing Job',
        analyzing: 'Analyzing Video',
        finalizing: 'Saving Results',
        complete: 'Complete',
        idle: 'Idle'
    };

    function setPanelState(panel, payload) {
        const status = String(payload.status || 'pending').toLowerCase();
        const stage = String(payload.stage || status).toLowerCase();
        const percent = Math.max(0, Math.min(100, Number(payload.percent || 0)));
        const stageNode = panel.querySelector('[data-progress-stage]');
        const percentNode = panel.querySelector('[data-progress-percent]');
        const barNode = panel.querySelector('[data-progress-bar]');
        const messageNode = panel.querySelector('[data-progress-message]');
        const detailNode = panel.querySelector('[data-progress-detail]');

        panel.classList.remove('hidden');
        panel.dataset.status = status;

        if (stageNode) {
            stageNode.textContent = stageLabel[stage] || stageLabel[status] || 'Processing';
        }
        if (percentNode) {
            percentNode.textContent = percent + '%';
        }
        if (barNode) {
            barNode.style.width = percent + '%';
            barNode.classList.remove('bg-primary', 'bg-accent-danger', 'bg-warning');
            barNode.classList.add(status === 'failed' ? 'bg-accent-danger' : status === 'processed' ? 'bg-primary' : 'bg-warning');
        }
        if (messageNode) {
            messageNode.textContent = String(payload.message || 'Waiting for status update...');
        }
        if (detailNode) {
            const currentFrame = Number(payload.current_frame || 0);
            const totalFrames = Number(payload.total_frames || 0);
            const elapsedVideo = Number(payload.elapsed_video_seconds || 0);
            const detailParts = [];
            if (currentFrame > 0 && totalFrames > 0) {
                detailParts.push('Frames: ' + currentFrame + ' / ' + totalFrames);
            }
            if (elapsedVideo > 0) {
                detailParts.push('Video Time: ' + elapsedVideo.toFixed(1) + 's');
            }
            if (payload.processed_at) {
                detailParts.push('Finished: ' + String(payload.processed_at));
            }
            detailNode.textContent = detailParts.join(' â€¢ ');
        }
    }

    async function refreshPanel(panel) {
        const matchId = panel.dataset.matchId;
        if (!matchId) {
            return;
        }

        try {
            const response = await fetch('/video-upload/progress?match_id=' + encodeURIComponent(matchId), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            setPanelState(panel, payload);
        } catch (error) {
            // Keep last rendered state if polling fails temporarily.
        }
    }

    panels.forEach((panel) => {
        refreshPanel(panel);
    });

    setInterval(() => {
        panels.forEach((panel) => {
            const status = String(panel.dataset.status || '').toLowerCase();
            if (status === 'queued' || status === 'processing') {
                refreshPanel(panel);
            }
        });
    }, 2500);

})();

