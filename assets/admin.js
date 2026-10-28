/**
 * ChatMaxima Admin JavaScript
 *
 * Handles AJAX operations for the admin settings page
 */
jQuery(document).ready(function($) {
    // Initialize color picker
    $('.chatmaxima-color-field').wpColorPicker();

    // Check if we're on the settings page
    if (typeof chatmaximaAdmin === 'undefined') {
        return;
    }

    // Store knowledge sources data globally
    var knowledgeSourcesData = [];

    // Store teams data globally
    var teamsData = [];

    // Track loading state to prevent race conditions
    var isLoadingTeams = false;
    var isLoadingChannels = false;
    var isLoadingKnowledgeSources = false;

    // Default AJAX timeout (15 seconds)
    var ajaxTimeout = 15000;

    // Max retry attempts
    var maxRetries = 2;

    var strings = chatmaximaAdmin.strings || {};

    // Published items in the selected post types and how many are synced (from PHP, updated after a sync)
    var syncState = {
        total: parseInt(chatmaximaAdmin.totalPosts, 10) || 0,
        synced: parseInt(chatmaximaAdmin.syncedPosts, 10) || 0
    };

    // Whether the widget is shown to visitors
    var widgetLive = !!chatmaximaAdmin.widgetInstalled;

    /**
     * Fill a "%1$s of %2$s" style string
     */
    function formatText(template, first, second) {
        return String(template || '').replace('%1$s', first).replace('%2$s', second);
    }

    /**
     * Synced-items meter in the Content sync card
     */
    function renderSyncCount() {
        var $count = $('#cm-sync-count');
        if ($count.length === 0) {
            return;
        }
        var synced = Math.min(syncState.synced, syncState.total);
        var percent = 0;
        if (syncState.total > 0) {
            percent = Math.round((synced / syncState.total) * 100);
        }
        $('#cm-sync-meter').css('width', percent + '%');
        $count.text(formatText(strings.syncedOf, synced.toLocaleString(), syncState.total.toLocaleString()));
    }

    /**
     * Set one setup step's state ('done', 'partial' or '') and its subtitle
     */
    function setStep(name, state, subtitle) {
        var $step = $('#chatmaxima-steps .cm-step[data-step="' + name + '"]');
        $step.removeClass('is-done is-partial is-current');
        if (state) {
            $step.addClass('is-' + state);
        }
        if (subtitle) {
            $('#cm-step-' + name + '-sub').text(subtitle);
        }
    }

    /**
     * Recompute the setup tracker from what is on the page
     */
    function updateSteps() {
        if ($('#chatmaxima-steps').length === 0) {
            return;
        }

        var $ks = $('#chatmaxima_knowledge_source option:selected');
        if ($ks.val()) {
            var source = $ks.data('source');
            var ksName = $ks.text();
            if (source && source.name) {
                ksName = source.name;
            }
            setStep('knowledge', 'done', ksName);
        } else {
            setStep('knowledge', '', strings.stepKnowledgeNone);
        }

        var synced = Math.min(syncState.synced, syncState.total);
        if (synced === 0) {
            setStep('sync', '', strings.stepSyncNone);
        } else if (synced >= syncState.total) {
            setStep('sync', 'done', formatText(strings.syncedOf, synced.toLocaleString(), syncState.total.toLocaleString()));
        } else {
            setStep('sync', 'partial', formatText(strings.syncedOf, synced.toLocaleString(), syncState.total.toLocaleString()));
        }

        if (widgetLive) {
            setStep('widget', 'done', strings.stepWidgetLive);
        } else if ($('#chatmaxima_channel').val()) {
            setStep('widget', 'partial', strings.stepWidgetPicked);
        } else {
            setStep('widget', '', strings.stepWidgetNone);
        }

        // Highlight the first step that still needs attention
        var $next = $('#chatmaxima-steps .cm-step').not('.is-done, .is-partial').first();
        if ($next.length === 0) {
            $next = $('#chatmaxima-steps .cm-step.is-partial').first();
        }
        $next.addClass('is-current');
    }

    /**
     * Widget status banner and switch
     */
    function renderWidgetState() {
        var $state = $('#chatmaxima-widget-state');
        $state.toggleClass('is-live', widgetLive).toggleClass('is-off', !widgetLive);
        if (widgetLive) {
            $('#chatmaxima-widget-state-text').text(strings.widgetLive);
        } else {
            $('#chatmaxima-widget-state-text').text(strings.widgetOff);
        }
        $('#chatmaxima-live-toggle').prop('checked', widgetLive);
    }

    renderSyncCount();
    renderWidgetState();
    updateSteps();

    // Load teams, knowledge sources, and channels on page load if authenticated
    // Load sequentially to avoid race conditions with token refresh
    if (chatmaximaAdmin.isAuthenticated) {
        loadTeamsWithRetry(0);
    }

    /**
     * Login handler
     */
    $('#chatmaxima-login-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#chatmaxima-login-message');
        var email = $('#chatmaxima_email').val();
        var password = $('#chatmaxima_password').val();

        if (!email || !password) {
            showMessage($message, 'Please enter email and password', 'error');
            return;
        }

        $btn.prop('disabled', true).text(chatmaximaAdmin.strings.connecting);
        $message.removeClass('success error').text('');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_login',
                nonce: chatmaximaAdmin.nonce,
                email: email,
                password: password
            },
            success: function(response) {
                if (response.success) {
                    showMessage($message, response.data.message, 'success');
                    // Reload page to show authenticated state
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage($message, response.data.message, 'error');
                    $btn.prop('disabled', false).text('Connect');
                }
            },
            error: function() {
                showMessage($message, 'Connection failed. Please try again.', 'error');
                $btn.prop('disabled', false).text('Connect');
            }
        });
    });

    /**
     * Logout handler
     */
    $('#chatmaxima-logout-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#chatmaxima-connection-message');

        $btn.prop('disabled', true);

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_logout',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showMessage($message, response.data.message, 'error');
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                showMessage($message, 'Disconnect failed. Please try again.', 'error');
                $btn.prop('disabled', false);
            }
        });
    });

    /**
     * Test connection handler
     */
    $('#chatmaxima-test-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#chatmaxima-connection-message');

        $btn.prop('disabled', true).text('Testing...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_test_connection',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage($message, 'Connection successful!', 'success');
                } else {
                    showMessage($message, response.data.message, 'error');
                }
                $btn.prop('disabled', false).text('Test Connection');
            },
            error: function() {
                showMessage($message, 'Connection test failed.', 'error');
                $btn.prop('disabled', false).text('Test Connection');
            }
        });
    });

    /**
     * Refresh knowledge sources handler
     */
    $('#chatmaxima-refresh-ks-btn').on('click', function() {
        loadKnowledgeSources();
    });

    /**
     * Refresh teams handler
     */
    $('#chatmaxima-refresh-teams-btn').on('click', function() {
        loadTeams();
    });

    /**
     * Workspace selection change - switch workspace
     */
    $('#chatmaxima_team').on('change', function() {
        var teamAlias = $(this).val();
        var $select = $(this);
        var $message = $('#chatmaxima-team-message');

        if (!teamAlias) {
            return;
        }

        $select.prop('disabled', true);
        $message.removeClass('success error').text('Switching workspace...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_switch_team',
                nonce: chatmaximaAdmin.nonce,
                team_alias: teamAlias
            },
            success: function(response) {
                if (response.success) {
                    showMessage($message, response.data.message, 'success');
                    // Reload the page to refresh all data with new team context
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage($message, response.data.message, 'error');
                    $select.prop('disabled', false);
                }
            },
            error: function() {
                showMessage($message, 'Failed to switch workspace. Please try again.', 'error');
                $select.prop('disabled', false);
            }
        });
    });

    /**
     * Knowledge source selection change - save to database
     */
    $('#chatmaxima_knowledge_source').on('change', function() {
        var alias = $(this).val();
        var $select = $(this);

        // Save via AJAX
        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_save_ks_selection',
                nonce: chatmaximaAdmin.nonce,
                alias: alias
            },
            success: function(response) {
                // Update details panel if it exists
                updateKsDetails(alias);
                updateSteps();
            }
        });
        updateSteps();
    });

    /**
     * Create knowledge source handler
     */
    $('#chatmaxima-create-ks-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#chatmaxima-create-ks-message');
        var name = $('#chatmaxima_new_ks_name').val();
        var llmType = $('#chatmaxima_new_ks_llm').val();

        if (!name) {
            showMessage($message, 'Please enter a name', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Creating...');
        $message.removeClass('success error').text('');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_create_knowledge_source',
                nonce: chatmaximaAdmin.nonce,
                name: name,
                llm_type: llmType
            },
            success: function(response) {
                if (response.success) {
                    showMessage($message, response.data.message, 'success');
                    $('#chatmaxima_new_ks_name').val('');
                    // Reload knowledge sources
                    loadKnowledgeSources();
                } else {
                    showMessage($message, response.data.message, 'error');
                }
                $btn.prop('disabled', false).text('Create Knowledge Source');
            },
            error: function() {
                showMessage($message, 'Failed to create knowledge source.', 'error');
                $btn.prop('disabled', false).text('Create Knowledge Source');
            }
        });
    });

    /**
     * Save post types and Auto Sync as soon as they change (they had no save path before)
     */
    var saveSyncSettingsTimer = null;
    $(document).on('change', '.chatmaxima-sync-post-type, #chatmaxima_auto_sync', function() {
        clearTimeout(saveSyncSettingsTimer);
        saveSyncSettingsTimer = setTimeout(saveSyncSettings, 300); // a burst of chip clicks saves once
    });

    function saveSyncSettings() {
        var $message = $('#chatmaxima-sync-settings-message');
        var postTypes = [];
        $('.chatmaxima-sync-post-type:checked').each(function() {
            postTypes.push($(this).val());
        });
        var autoSync = '0';
        if ($('#chatmaxima_auto_sync').is(':checked')) {
            autoSync = '1';
        }

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_save_sync_settings',
                nonce: chatmaximaAdmin.nonce,
                post_types: postTypes,
                auto_sync: autoSync
            },
            success: function(response) {
                if (response.success) {
                    syncState.total = parseInt(response.data.total, 10) || 0;
                    syncState.synced = parseInt(response.data.synced, 10) || 0;
                    renderSyncCount();
                    updateSteps();
                    showMessage($message, strings.saved, 'success');
                    setTimeout(function() {
                        $message.removeClass('success').text('');
                    }, 1800);
                } else {
                    showMessage($message, (response.data && response.data.message) || strings.saveFailed, 'error');
                }
            },
            error: function() {
                showMessage($message, strings.saveFailed, 'error');
            }
        });
    }

    /**
     * Sync all content handler
     */
    $('#chatmaxima-sync-all-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#chatmaxima-sync-message');
        var $progress = $('#chatmaxima-sync-progress');
        var knowledgeSource = $('#chatmaxima_knowledge_source').val();

        // If no dropdown value, check if we have a saved value
        if (!knowledgeSource && chatmaximaAdmin.selectedKnowledgeSource) {
            knowledgeSource = chatmaximaAdmin.selectedKnowledgeSource;
        }

        if (!knowledgeSource) {
            showMessage($message, 'Please select a knowledge source first', 'error');
            return;
        }

        // Get selected post types
        var postTypes = [];
        $('.chatmaxima-sync-post-type:checked').each(function() {
            postTypes.push($(this).val());
        });

        if (postTypes.length === 0) {
            showMessage($message, 'Please select at least one post type', 'error');
            return;
        }

        $btn.prop('disabled', true);
        $progress.show();
        $message.removeClass('success error').text('');

        syncBatch(0, postTypes, $progress, $btn, $message);
    });

    /**
     * Load knowledge sources via AJAX with retry support
     */
    function loadKnowledgeSourcesWithRetry(attempt) {
        var $select = $('#chatmaxima_knowledge_source');
        var $btn = $('#chatmaxima-refresh-ks-btn');

        if ($select.length === 0) {
            // No dropdown, proceed to load channels
            loadChannelsWithRetry(0);
            return;
        }

        // Prevent concurrent loads
        if (isLoadingKnowledgeSources) {
            return;
        }
        isLoadingKnowledgeSources = true;

        $btn.prop('disabled', true).text('Loading...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            timeout: ajaxTimeout,
            data: {
                action: 'chatmaxima_get_knowledge_sources',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
                isLoadingKnowledgeSources = false;
                if (response.success) {
                    var sources = response.data.knowledge_sources;
                    var selected = response.data.selected;

                    // Store data globally
                    knowledgeSourcesData = sources || [];

                    $select.find('option:not(:first)').remove();

                    if (sources && sources.length > 0) {
                        sources.forEach(function(source) {
                            var $option = $('<option></option>')
                                .val(source.alias)
                                .text(source.name + ' (' + source.document_count + ' docs)')
                                .data('source', source);

                            if (source.alias === selected) {
                                $option.prop('selected', true);
                            }

                            $select.append($option);
                        });

                        // Update details panel if selected
                        if (selected) {
                            updateKsDetails(selected);
                        }
                    }
                    updateSteps();
                    $btn.prop('disabled', false).text('Refresh');

                    // Load channels after knowledge sources loaded successfully
                    loadChannelsWithRetry(0);
                } else {
                    // Retry on failure
                    if (attempt < maxRetries) {
                        console.log('Retrying knowledge sources load, attempt ' + (attempt + 1));
                        setTimeout(function() {
                            isLoadingKnowledgeSources = false;
                            loadKnowledgeSourcesWithRetry(attempt + 1);
                        }, Math.pow(2, attempt) * 1000);
                    } else {
                        $btn.prop('disabled', false).text('Refresh');
                        // Still try to load channels
                        loadChannelsWithRetry(0);
                    }
                }
            },
            error: function(xhr, status, error) {
                isLoadingKnowledgeSources = false;
                // Retry on network error
                if (attempt < maxRetries) {
                    console.log('Retrying knowledge sources load after error, attempt ' + (attempt + 1));
                    setTimeout(function() {
                        loadKnowledgeSourcesWithRetry(attempt + 1);
                    }, Math.pow(2, attempt) * 1000);
                } else {
                    $btn.prop('disabled', false).text('Refresh');
                    // Still try to load channels
                    loadChannelsWithRetry(0);
                }
            }
        });
    }

    /**
     * Load knowledge sources via AJAX (wrapper for manual refresh)
     */
    function loadKnowledgeSources() {
        loadKnowledgeSourcesWithRetry(0);
    }

    /**
     * Load teams via AJAX with retry support
     */
    function loadTeamsWithRetry(attempt) {
        var $select = $('#chatmaxima_team');
        var $btn = $('#chatmaxima-refresh-teams-btn');

        if ($select.length === 0) {
            // No dropdown, proceed to load other data
            loadKnowledgeSourcesWithRetry(0);
            return;
        }

        // Prevent concurrent loads
        if (isLoadingTeams) {
            return;
        }
        isLoadingTeams = true;

        $btn.prop('disabled', true).text('Loading...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            timeout: ajaxTimeout,
            data: {
                action: 'chatmaxima_get_teams',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
                isLoadingTeams = false;
                if (response.success) {
                    var teams = response.data.teams;
                    var selected = response.data.selected;

                    // Store data globally
                    teamsData = teams || [];

                    $select.find('option').remove();
                    $select.append($('<option></option>').val('').text('-- Select Workspace --'));

                    if (teams && teams.length > 0) {
                        teams.forEach(function(team) {
                            var $option = $('<option></option>')
                                .val(team.team_alias)
                                .text(team.team_name)
                                .data('team', team);

                            if (team.team_alias === selected) {
                                $option.prop('selected', true);
                            }

                            $select.append($option);
                        });
                    }

                    // Show message if only one workspace
                    if (teams && teams.length === 1) {
                        $('#chatmaxima-team-message').text('You have access to 1 workspace').addClass('success');
                    } else if (teams && teams.length > 1) {
                        $('#chatmaxima-team-message').text(teams.length + ' workspaces available').addClass('success');
                    }
                    $btn.prop('disabled', false).text('Refresh');

                    // Load knowledge sources after teams loaded successfully
                    loadKnowledgeSourcesWithRetry(0);
                } else {
                    // Retry on failure
                    if (attempt < maxRetries) {
                        console.log('Retrying teams load, attempt ' + (attempt + 1));
                        setTimeout(function() {
                            isLoadingTeams = false;
                            loadTeamsWithRetry(attempt + 1);
                        }, Math.pow(2, attempt) * 1000); // Exponential backoff: 1s, 2s, 4s
                    } else {
                        showMessage($('#chatmaxima-team-message'), response.data.message || 'Failed to load workspaces', 'error');
                        console.error('Load teams error:', response);
                        $btn.prop('disabled', false).text('Refresh');
                        // Still try to load other data
                        loadKnowledgeSourcesWithRetry(0);
                    }
                }
            },
            error: function(xhr, status, error) {
                isLoadingTeams = false;
                // Retry on network error
                if (attempt < maxRetries) {
                    console.log('Retrying teams load after error, attempt ' + (attempt + 1));
                    setTimeout(function() {
                        loadTeamsWithRetry(attempt + 1);
                    }, Math.pow(2, attempt) * 1000);
                } else {
                    showMessage($('#chatmaxima-team-message'), 'Failed to load workspaces: ' + error, 'error');
                    console.error('Load teams AJAX error:', xhr, status, error);
                    $btn.prop('disabled', false).text('Refresh');
                    // Still try to load other data
                    loadKnowledgeSourcesWithRetry(0);
                }
            }
        });
    }

    /**
     * Load teams via AJAX (wrapper for manual refresh)
     */
    function loadTeams() {
        loadTeamsWithRetry(0);
    }

    /**
     * Update knowledge source details panel
     */
    function updateKsDetails(alias) {
        var $details = $('#chatmaxima-ks-details');

        if ($details.length === 0 || !alias) {
            $details.hide();
            return;
        }

        // Find source data
        var source = null;
        for (var i = 0; i < knowledgeSourcesData.length; i++) {
            if (knowledgeSourcesData[i].alias === alias) {
                source = knowledgeSourcesData[i];
                break;
            }
        }

        if (source) {
            $('#ks-detail-name').text(source.name);
            $('#ks-detail-docs').text(source.document_count);
            $('#ks-detail-storage').text(source.storage_size);
            $('#ks-detail-type').text(source.crawl_type || 'web');
            $details.show();
        } else {
            $details.hide();
        }
    }

    /**
     * Sync content in batches
     */
    function syncBatch(offset, postTypes, $progress, $btn, $message) {
        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_sync_posts',
                nonce: chatmaximaAdmin.nonce,
                post_types: postTypes,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var percent = data.total > 0 ? Math.round((data.synced / data.total) * 100) : 100;

                    $progress.find('.progress-fill').css('width', percent + '%');
                    $progress.find('.progress-count').text(data.synced + '/' + data.total);

                    if (data.complete) {
                        showMessage($message, chatmaximaAdmin.strings.syncComplete + ' ' + data.synced + ' items synced.', 'success');
                        $btn.prop('disabled', false);
                        // Every item in the run was sent and marked synced
                        syncState.total = parseInt(data.total, 10) || syncState.total;
                        syncState.synced = parseInt(data.synced, 10) || 0;
                        renderSyncCount();
                        updateSteps();
                        loadKnowledgeSources(); // refresh the document count
                        setTimeout(function() {
                            $progress.hide();
                        }, 2000);
                    } else {
                        // Continue with next batch
                        syncBatch(data.next_offset, postTypes, $progress, $btn, $message);
                    }
                } else {
                    showMessage($message, response.data.message, 'error');
                    $btn.prop('disabled', false);
                    $progress.hide();
                }
            },
            error: function() {
                showMessage($message, 'Sync failed. Please try again.', 'error');
                $btn.prop('disabled', false);
                $progress.hide();
            }
        });
    }

    /**
     * Refresh channels handler
     */
    $('#chatmaxima-refresh-channels-btn').on('click', function() {
        loadChannels();
    });

    /**
     * Channel selection change - show script preview and save selection
     */
    $('#chatmaxima_channel').on('change', function() {
        var alias = $(this).val();

        if (alias) {
            // Show widget script section
            $('#chatmaxima-widget-script').show();
            updateScriptPreview(alias);

            // Save channel selection to database
            $.ajax({
                url: chatmaximaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatmaxima_save_channel_selection',
                    nonce: chatmaximaAdmin.nonce,
                    alias: alias
                },
                success: function(response) {
                    if (response.success) {
                        showMessage($('#chatmaxima-channel-message'), 'Channel selected', 'success');
                        if (widgetLive) {
                            // The widget is live: move it to the newly selected channel
                            $.post(chatmaximaAdmin.ajaxUrl, {
                                action: 'chatmaxima_install_widget',
                                nonce: chatmaximaAdmin.nonce,
                                alias: alias
                            });
                        }
                    }
                }
            });
        } else {
            $('#chatmaxima-widget-script').hide();
        }
        updateSteps();
    });

    /**
     * Update script preview with selected channel alias
     */
    function updateScriptPreview(alias) {
        var scriptCode = '<script>\n' +
            '    window.chatmaximaConfig = { token: \'' + alias + '\' }\n' +
            '</script>\n' +
            '<script src="https://widget.chatmaxima.com/embed.min.js" id="' + alias + '" defer></script>';

        $('#chatmaxima-script-code').text(scriptCode);
    }

    /**
     * Preview Widget button handler - Load widget in admin area only
     */
    var previewWidgetLoaded = false;
    $('#chatmaxima-preview-widget-btn').on('click', function() {
        var alias = $('#chatmaxima_channel').val();
        var $btn = $(this);

        if (!alias) {
            showMessage($('#chatmaxima-install-message'), 'Please select a channel first', 'error');
            return;
        }

        if (previewWidgetLoaded) {
            // Remove preview widget - reload page is the cleanest way
            // since widget scripts often can't be cleanly unloaded
            showMessage($('#chatmaxima-install-message'), 'Removing preview...', 'success');
            previewWidgetLoaded = false;

            // Remove injected scripts
            $('#chatmaxima-preview-script').remove();
            $('#chatmaxima-preview-config').remove();

            // Remove widget iframe and container elements
            // The widget typically creates elements like cm-widget, cm-frame, etc.
            $('iframe[src*="chatmaxima"]').remove();
            $('iframe[id*="chatmaxima"]').remove();
            $('[id^="cm-"]').remove();
            $('[class^="cm-"]').remove();
            $('[id*="chatmaxima"]').not('.chatmaxima-settings-wrap, .chatmaxima-settings-wrap *').remove();
            $('[class*="widget-chatmaxima"]').remove();

            // Clear the global config
            if (window.chatmaximaConfig) {
                delete window.chatmaximaConfig;
            }
            if (window.ChatMaxima) {
                delete window.ChatMaxima;
            }

            $btn.text(strings.previewWidget || 'Preview Widget');
            showMessage($('#chatmaxima-install-message'), 'Preview widget removed. Refresh the page if the widget is still visible.', 'success');
            return;
        }

        $btn.prop('disabled', true).text('Loading...');

        // Inject the widget script dynamically for admin preview
        window.chatmaximaConfig = { token: alias };

        var configScript = document.createElement('script');
        configScript.id = 'chatmaxima-preview-config';
        configScript.textContent = 'window.chatmaximaConfig = { token: "' + alias + '" };';
        document.body.appendChild(configScript);

        var widgetScript = document.createElement('script');
        widgetScript.id = 'chatmaxima-preview-script';
        widgetScript.src = 'https://widget.chatmaxima.com/embed.min.js';
        widgetScript.defer = true;
        widgetScript.onload = function() {
            previewWidgetLoaded = true;
            $btn.prop('disabled', false).text(strings.hidePreview || 'Hide Preview');
            showMessage($('#chatmaxima-install-message'), 'Widget preview loaded! You can now test the chatbot.', 'success');
        };
        widgetScript.onerror = function() {
            $btn.prop('disabled', false).text(strings.previewWidget || 'Preview Widget');
            showMessage($('#chatmaxima-install-message'), 'Failed to load widget preview', 'error');
        };
        document.body.appendChild(widgetScript);
    });

    /**
     * Copy script button handler
     */
    $('#chatmaxima-copy-script-btn').on('click', function() {
        var scriptCode = $('#chatmaxima-script-code').text();
        var $copyBtn = $(this);
        var copyLabel = $copyBtn.text();
        navigator.clipboard.writeText(scriptCode).then(function() {
            $copyBtn.text(strings.copied || 'Copied');
            setTimeout(function() {
                $copyBtn.text(copyLabel);
            }, 1500);
        }).catch(function() {
            // Fallback for older browsers
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(scriptCode).select();
            document.execCommand('copy');
            $temp.remove();
            showMessage($('#chatmaxima-install-message'), 'Script copied to clipboard!', 'success');
        });
    });

    /**
     * "Show on your site" switch: installs or removes the widget for visitors
     */
    $('#chatmaxima-live-toggle').on('change', function() {
        var $toggle = $(this);
        var turnOn = $toggle.is(':checked');
        var alias = $('#chatmaxima_channel').val();
        var $message = $('#chatmaxima-install-message');

        if (turnOn && !alias) {
            $toggle.prop('checked', false);
            showMessage($message, strings.selectChannelFirst, 'error');
            return;
        }

        $toggle.prop('disabled', true);
        if (turnOn) {
            $message.removeClass('success error').text(strings.enabling);
        } else {
            $message.removeClass('success error').text(strings.disabling);
        }

        var data = {
            action: 'chatmaxima_uninstall_widget',
            nonce: chatmaximaAdmin.nonce
        };
        if (turnOn) {
            data = {
                action: 'chatmaxima_install_widget',
                nonce: chatmaximaAdmin.nonce,
                alias: alias
            };
        }

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                $toggle.prop('disabled', false);
                if (response.success) {
                    widgetLive = turnOn;
                    showMessage($message, response.data.message, 'success');
                } else {
                    $toggle.prop('checked', widgetLive); // put the switch back
                    showMessage($message, (response.data && response.data.message) || strings.saveFailed, 'error');
                }
                renderWidgetState();
                updateSteps();
            },
            error: function() {
                $toggle.prop('disabled', false).prop('checked', widgetLive);
                showMessage($message, strings.saveFailed, 'error');
                renderWidgetState();
            }
        });
    });

    /**
     * Load channels via AJAX with retry support
     */
    function loadChannelsWithRetry(attempt) {
        var $select = $('#chatmaxima_channel');
        var $btn = $('#chatmaxima-refresh-channels-btn');

        if ($select.length === 0) {
            return; // No dropdown on this page
        }

        // Prevent concurrent loads
        if (isLoadingChannels) {
            return;
        }
        isLoadingChannels = true;

        $btn.prop('disabled', true).text('Loading...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            timeout: ajaxTimeout,
            data: {
                action: 'chatmaxima_get_channels',
                nonce: chatmaximaAdmin.nonce,
                platform: 'livechatwidget' // Only load web channels for WordPress widget
            },
            success: function(response) {
                isLoadingChannels = false;
                if (response.success) {
                    var channels = response.data.channels;
                    var selected = response.data.selected;

                    console.log('Channels loaded:', channels.length, 'Selected:', selected); // Debug

                    $select.find('option').remove();
                    $select.append($('<option></option>').val('').text('-- Select Channel --'));

                    var foundSelected = false;
                    if (channels && channels.length > 0) {
                        channels.forEach(function(channel) {
                            // API v2 returns the channel alias as account_id and its name as account_name; older responses used alias and name
                            var channelAlias = channel.account_id || channel.alias;
                            var displayName = channel.account_name || channel.name || channelAlias;
                            var $option = $('<option></option>')
                                .val(channelAlias)
                                .text(displayName)
                                .data('channel', channel);

                            if (selected && channelAlias === selected) {
                                $option.prop('selected', true);
                                foundSelected = true;
                                console.log('Selected channel found:', channelAlias); // Debug
                            }

                            $select.append($option);
                        });

                        // Update script preview if selected
                        if (selected && foundSelected) {
                            $('#chatmaxima-widget-script').show();
                            updateScriptPreview(selected);
                        }

                        // The selected channel's name is already in the dropdown, so only the count is shown
                        $('#chatmaxima-channel-message').text(channels.length + ' channel(s) available').addClass('success');
                    } else {
                        $('#chatmaxima-channel-message').text('No web channels found. Create one in ChatMaxima dashboard.').addClass('error');
                    }
                    updateSteps();
                    $btn.prop('disabled', false).text('Refresh');
                } else {
                    // Retry on failure
                    if (attempt < maxRetries) {
                        console.log('Retrying channels load, attempt ' + (attempt + 1));
                        setTimeout(function() {
                            isLoadingChannels = false;
                            loadChannelsWithRetry(attempt + 1);
                        }, Math.pow(2, attempt) * 1000);
                    } else {
                        console.error('Channels AJAX error response:', response);
                        showMessage($('#chatmaxima-channel-message'), response.data.message || 'Failed to load channels', 'error');
                        $btn.prop('disabled', false).text('Refresh');
                    }
                }
            },
            error: function(xhr, status, error) {
                isLoadingChannels = false;
                // Retry on network error
                if (attempt < maxRetries) {
                    console.log('Retrying channels load after error, attempt ' + (attempt + 1));
                    setTimeout(function() {
                        loadChannelsWithRetry(attempt + 1);
                    }, Math.pow(2, attempt) * 1000);
                } else {
                    console.error('Channels AJAX request failed:', error, xhr.responseText);
                    showMessage($('#chatmaxima-channel-message'), 'Failed to load channels: ' + error, 'error');
                    $btn.prop('disabled', false).text('Refresh');
                }
            }
        });
    }

    /**
     * Load channels via AJAX (wrapper for manual refresh)
     */
    function loadChannels() {
        loadChannelsWithRetry(0);
    }

    /**
     * Show message helper
     */
    function showMessage($element, message, type) {
        $element.removeClass('success error').addClass(type).text(message);
    }
});
