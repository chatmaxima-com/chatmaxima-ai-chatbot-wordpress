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

    // Load teams and knowledge sources on page load if authenticated
    if (chatmaximaAdmin.isAuthenticated) {
        loadTeams();
        loadKnowledgeSources();
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
     * Team selection change - switch team
     */
    $('#chatmaxima_team').on('change', function() {
        var teamAlias = $(this).val();
        var $select = $(this);
        var $message = $('#chatmaxima-team-message');

        if (!teamAlias) {
            return;
        }

        $select.prop('disabled', true);
        $message.removeClass('success error').text('Switching team...');

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
                showMessage($message, 'Failed to switch team. Please try again.', 'error');
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
            }
        });
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
     * Load knowledge sources via AJAX
     */
    function loadKnowledgeSources() {
        var $select = $('#chatmaxima_knowledge_source');
        var $btn = $('#chatmaxima-refresh-ks-btn');

        if ($select.length === 0) {
            return; // No dropdown on this page
        }

        $btn.prop('disabled', true).text('Loading...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_get_knowledge_sources',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
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
                }
                $btn.prop('disabled', false).text('Refresh');
            },
            error: function() {
                $btn.prop('disabled', false).text('Refresh');
            }
        });
    }

    /**
     * Load teams via AJAX
     */
    function loadTeams() {
        var $select = $('#chatmaxima_team');
        var $btn = $('#chatmaxima-refresh-teams-btn');

        console.log('loadTeams called, select element found:', $select.length > 0);

        if ($select.length === 0) {
            console.log('Team dropdown not found on page, skipping loadTeams');
            return; // No dropdown on this page
        }

        $btn.prop('disabled', true).text('Loading...');
        console.log('Making AJAX call to get teams...');

        $.ajax({
            url: chatmaximaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_get_teams',
                nonce: chatmaximaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var teams = response.data.teams;
                    var selected = response.data.selected;

                    // Store data globally
                    teamsData = teams || [];

                    $select.find('option').remove();
                    $select.append($('<option></option>').val('').text('-- Select Team --'));

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

                    // Show message if only one team
                    if (teams && teams.length === 1) {
                        $('#chatmaxima-team-message').text('You have access to 1 team').addClass('success');
                    } else if (teams && teams.length > 1) {
                        $('#chatmaxima-team-message').text(teams.length + ' teams available').addClass('success');
                    }
                } else {
                    // Show error message
                    showMessage($('#chatmaxima-team-message'), response.data.message || 'Failed to load teams', 'error');
                    console.error('Load teams error:', response);
                }
                $btn.prop('disabled', false).text('Refresh');
            },
            error: function(xhr, status, error) {
                showMessage($('#chatmaxima-team-message'), 'Failed to load teams: ' + error, 'error');
                console.error('Load teams AJAX error:', xhr, status, error);
                $btn.prop('disabled', false).text('Refresh');
            }
        });
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
     * Show message helper
     */
    function showMessage($element, message, type) {
        $element.removeClass('success error').addClass(type).text(message);
    }
});
