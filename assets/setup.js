/**
 * ChatMaxima setup wizard and Home (admin.php?page=chatmaxima-settings)
 *
 * Steps: Connect, Workspace (only with 2+ workspaces), Knowledge, Chatbot (Persona, AI model), Install.
 * Every server call goes through admin-ajax with the plugin nonce; the PHP side talks to ChatMaxima API v2.
 */
(function ($) {
    'use strict';

    var C = window.chatmaximaSetup || {};
    var S = C.strings || {};
    var $app = $('#cmw-app');

    var ui = {
        step: C.step || 'connect',
        teams: null,
        workspaceNeeded: false,
        bot: null,
        botLoaded: false,
        chatbotTab: 'persona',
        knowledge: { mode: 'new', sources: null, name: (C.site && C.site.name) || '', inactive: false, limitUrl: '' },
        sync: { running: false, paused: false, synced: 0, total: 0, items: [], error: '', done: false, offset: 0 },
        persona: null,
        model: { provider: 'chatmaxima', model: 'maxia-v4', credentialId: '', keyTested: false, models: [], keys: [] },
        channel: null,
        widgetLoaded: false,
        preview: { busy: false, question: '', answer: '', welcome: '' }
    };

    // ---- helpers ----

    // HTML-escape for text AND attribute contexts (quotes included). Every value from ChatMaxima or WordPress that
    // is written into markup goes through this, because workspace and bot names are set by other people.
    var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function esc(value) {
        if (value === undefined || value === null) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, function (ch) { return ESC_MAP[ch]; });
    }

    // Only https URLs may go into src/href; anything else falls back to nothing
    function safeUrl(value) {
        var url = String(value || '').trim();
        if (/^https:\/\//i.test(url)) {
            return url;
        }
        return '';
    }

    // sprintf for %s and %1$s placeholders
    function fmt(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;
        return String(template || '').replace(/%(\d+\$)?s/g, function (match, position) {
            var value;
            if (position) {
                value = args[parseInt(position, 10) - 1];
            } else {
                value = args[index];
                index = index + 1;
            }
            if (value === undefined) {
                return '';
            }
            return value;
        });
    }

    function post(action, data, files) {
        var deferred = $.Deferred();
        var settings = { url: C.ajaxUrl, method: 'POST', dataType: 'json' };
        if (files) {
            var form = new FormData();
            form.append('action', action);
            form.append('nonce', C.nonce);
            $.each(data || {}, function (key, value) { form.append(key, value); });
            $.each(files, function (key, file) { form.append(key, file); });
            settings.data = form;
            settings.processData = false;
            settings.contentType = false;
        } else {
            settings.data = $.extend({ action: action, nonce: C.nonce }, data || {});
        }
        $.ajax(settings).done(function (response) {
            if (response && response.success) {
                deferred.resolve(response.data || {});
            } else {
                var error = (response && response.data) || {};
                deferred.reject({ message: error.message || S.error, code: error.code || '', upgradeUrl: error.upgrade_url || '' });
            }
        }).fail(function (xhr, status, thrown) {
            console.error('ChatMaxima request failed:', action, status, thrown);
            deferred.reject({ message: S.error, code: 'network' });
        });
        return deferred.promise();
    }

    function notice(message, type) {
        var $notice = $('#cmw-notice');
        if (!message) {
            $notice.prop('hidden', true).empty();
            return;
        }
        $notice.attr('class', 'cmw-notice cmw-notice-' + (type || 'info')).text(message).prop('hidden', false);
        if (type === 'success') {
            setTimeout(function () { $notice.prop('hidden', true); }, 4000);
        }
    }

    // In-page confirm (never window.confirm)
    function confirmBox(message, onYes) {
        var $overlay = $('<div class="cmw-overlay" role="dialog" aria-modal="true"></div>');
        var $box = $('<div class="cmw-dialog"></div>');
        $box.append($('<p></p>').text(message));
        var $actions = $('<div class="cmw-dialog-actions"></div>');
        var $no = $('<button type="button" class="button"></button>').text(S.cancel);
        var $yes = $('<button type="button" class="button button-primary"></button>').text(S.confirm);
        $actions.append($no, $yes);
        $box.append($actions);
        $overlay.append($box);
        $('body').append($overlay);
        $yes.trigger('focus');
        $no.on('click', function () { $overlay.remove(); });
        $yes.on('click', function () { $overlay.remove(); onYes(); });
        $overlay.on('keydown', function (event) {
            if (event.key === 'Escape') {
                $overlay.remove();
            }
        });
    }

    function busy($button, label) {
        if (!$button.data('label')) {
            $button.data('label', $button.html());
        }
        $button.prop('disabled', true).text(label);
    }

    function unbusy($button) {
        $button.prop('disabled', false).html($button.data('label'));
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/);
        var letters = '';
        if (parts[0]) {
            letters = parts[0].charAt(0);
        }
        if (parts.length > 1 && parts[1]) {
            letters = letters + parts[1].charAt(0);
        }
        return letters.toUpperCase() || '?';
    }

    function botDisplayName() {
        if (ui.persona && ui.persona.name) {
            return ui.persona.name;
        }
        if (ui.bot && ui.bot.name) {
            return ui.bot.name;
        }
        return S.chatbot;
    }

    function saveStep(step, extra) {
        ui.step = step;
        return post('chatmaxima_wiz_state', $.extend({ step: step }, extra || {}));
    }

    function steps() {
        var list = [{ key: 'connect', title: S.stepConnect, sub: S.stepConnectSub }];
        if (ui.workspaceNeeded) {
            list.push({ key: 'workspace', title: S.stepWorkspace, sub: S.stepWorkspaceSub });
        }
        list.push({ key: 'knowledge', title: S.stepKnowledge, sub: S.stepKnowledgeSub });
        list.push({ key: 'chatbot', title: S.stepChatbot, sub: S.stepChatbotSub });
        list.push({ key: 'install', title: S.stepInstall, sub: S.stepInstallSub });
        return list;
    }

    function stepIndex(key) {
        var list = steps();
        for (var i = 0; i < list.length; i++) {
            if (list[i].key === key) {
                return i;
            }
        }
        return 0;
    }

    function stepDoneLabel(key) {
        if (key === 'connect') {
            return C.user.email;
        }
        if (key === 'workspace') {
            return C.user.teamName;
        }
        if (key === 'knowledge') {
            if (ui.sync.done && ui.sync.total) {
                return fmt(S.syncDone, ui.sync.total);
            }
            return '';
        }
        if (key === 'chatbot') {
            var label = botDisplayName();
            if (C.state.model_label) {
                label = label + ' · ' + C.state.model_label;
            }
            return label;
        }
        return '';
    }

    // ---- layout ----

    function renderShell(inner) {
        var list = steps();
        var current = stepIndex(ui.step);
        var html = '<div class="cmw-layout' + (C.editing ? ' cmw-layout-single' : '') + '">';
        if (!C.editing) {
            html += '<aside class="cmw-steps" aria-label="' + esc(fmt(S.setupSteps, list.length)) + '">';
            html += '<div class="cmw-steps-head">' + esc(fmt(S.setupSteps, list.length)) + '</div><ol>';
            for (var i = 0; i < list.length; i++) {
                var cls = 'cmw-step';
                var marker = String(i + 1);
                var sub = list[i].sub;
                if (i < current) {
                    cls += ' is-done';
                    marker = '<span class="dashicons dashicons-yes" aria-hidden="true"></span>';
                    sub = stepDoneLabel(list[i].key) || sub;
                } else if (i === current) {
                    cls += ' is-current';
                }
                html += '<li class="' + cls + '"' + (i === current ? ' aria-current="step"' : '') + '><span class="cmw-step-num">' + marker + '</span><span class="cmw-step-text"><span class="cmw-step-title">' + esc(list[i].title) + '</span><span class="cmw-step-sub">' + esc(sub) + '</span></span></li>';
            }
            html += '</ol><div class="cmw-steps-foot">' + esc(ui.step === 'connect' ? S.aboutFive : S.progressSaved);
            if (C.isAuthenticated) {
                html += '<div class="cmw-signed-in">' + esc(fmt(S.signedInAs, C.user.email)) + ' <button type="button" class="cmw-link-btn" id="cmw-sign-out">' + esc(S.signOut) + '</button></div>';
            }
            html += '</div></aside>';
        }
        html += '<section class="cmw-panel">';
        if (!C.editing) {
            html += '<div class="cmw-eyebrow">' + esc(fmt(S.stepOf, current + 1, list.length)) + '</div>';
        }
        html += inner + '</section></div>';
        $app.html(html);
        $('#cmw-sign-out').on('click', function () {
            confirmBox(S.signOutConfirm, function () {
                post('chatmaxima_logout').always(function () { window.location.href = C.homeUrl; });
            });
        });
    }

    function footer(backHtml, rightHtml) {
        return '<div class="cmw-footer"><div>' + (backHtml || '') + '</div><div class="cmw-footer-right">' + (rightHtml || '') + '</div></div>';
    }

    function go(step) {
        notice('');
        ui.step = step;
        if (step === 'connect') {
            renderConnect('signin');
        } else if (step === 'workspace') {
            renderWorkspace();
        } else if (step === 'knowledge') {
            renderKnowledge();
        } else if (step === 'chatbot' || step === 'model') {
            ui.step = 'chatbot';
            if (step === 'model') {
                ui.chatbotTab = 'model';
            }
            ensureBot().always(renderChatbot);
        } else {
            ui.step = 'install';
            ensureBot().always(renderInstall);
        }
        window.scrollTo(0, 0);
    }

    function ensureBot() {
        if (ui.botLoaded) {
            return $.Deferred().resolve().promise();
        }
        return post('chatmaxima_wiz_get_bot').done(function (data) {
            ui.botLoaded = true;
            ui.bot = data.bot || null;
            if (data.missing) {
                notice(S.botMissing, 'warning');
            }
        }).fail(function (error) {
            notice(error.message, 'error');
        });
    }

    // ---- 1. Connect ----

    function renderConnect(mode) {
        var site = (C.site && C.site.name) || '';
        var html = '<h2 class="cmw-h">' + esc(S.connectTitle) + '</h2><p class="cmw-intro">' + esc(S.connectIntro) + '</p>';
        html += '<div class="cmw-segment" role="tablist">';
        html += '<button type="button" role="tab" class="cmw-seg' + (mode === 'signin' ? ' is-active' : '') + '" data-mode="signin" aria-selected="' + (mode === 'signin') + '">' + esc(S.haveAccount) + '</button>';
        html += '<button type="button" role="tab" class="cmw-seg' + (mode === 'signup' ? ' is-active' : '') + '" data-mode="signup" aria-selected="' + (mode === 'signup') + '">' + esc(S.newAccount) + '</button></div>';
        if (!C.isSsl) {
            html += '<div class="cmw-inline-warning">' + esc(S.noSsl) + '</div>';
        }
        html += '<div class="cmw-cols"><form class="cmw-form" id="cmw-connect-form" novalidate>';
        if (mode === 'signup') {
            html += field('cmw-name', S.yourName, 'text', 'name', '');
        }
        html += field('cmw-email', S.email, 'email', 'email', '');
        html += '<div class="cmw-field"><div class="cmw-label-row"><label for="cmw-password">' + esc(S.password) + '</label>';
        if (mode === 'signin') {
            html += '<a href="https://chatmaxima.com/login/forgot/" target="_blank" rel="noopener noreferrer">' + esc(S.forgotPassword) + '</a>';
        }
        html += '</div><div class="cmw-password"><input id="cmw-password" type="password" class="regular-text" autocomplete="' + (mode === 'signup' ? 'new-password' : 'current-password') + '"><button type="button" class="cmw-link-btn" id="cmw-toggle-password">' + esc(S.show) + '</button></div>';
        if (mode === 'signup') {
            html += '<div class="cmw-rules"><span id="cmw-rule-len">' + esc(S.rule8) + '</span><span id="cmw-rule-mix">' + esc(S.ruleMix) + '</span></div>';
        }
        html += '</div>';
        if (mode === 'signup') {
            html += '<label class="cmw-check"><input type="checkbox" id="cmw-agree"> <span>' + fmt(esc(S.agreeTerms), '<a href="https://chatmaxima.com/tos/" target="_blank" rel="noopener noreferrer">', '</a>', '<a href="https://chatmaxima.com/privacy/" target="_blank" rel="noopener noreferrer">', '</a>') + '</span></label>';
            html += '<p><button type="submit" class="button button-primary button-hero cmw-primary" id="cmw-connect-submit">' + esc(S.createContinue) + '</button></p>';
        } else {
            html += '<p><button type="submit" class="button button-primary button-hero cmw-primary" id="cmw-connect-submit">' + esc(S.signInContinue) + '</button></p>';
            html += '<p class="cmw-muted">' + esc(S.newHere) + ' <a href="#" data-mode="signup" class="cmw-mode-link">' + esc(S.createFree) + '</a></p>';
        }
        html += '<div id="cmw-connect-error" class="cmw-inline-error" role="alert" hidden></div></form>';
        html += '<div class="cmw-aside-box"><h3>' + esc(mode === 'signup' ? S.yourNewAccount : S.whatConnecting) + '</h3><ul class="cmw-ticks">';
        if (mode === 'signup') {
            html += '<li>' + esc(S.newPoint1) + '</li><li>' + esc(S.newPoint2) + '</li><li>' + esc(fmt(S.newPoint3, site)) + '</li>';
        } else {
            html += '<li>' + esc(S.connectPoint1) + '</li><li>' + esc(S.connectPoint2) + '</li><li>' + esc(S.connectPoint3) + '</li>';
        }
        html += '</ul></div></div>';
        renderShell(html);

        $app.find('[data-mode]').on('click', function (event) {
            event.preventDefault();
            renderConnect($(this).data('mode'));
        });
        $('#cmw-toggle-password').on('click', function () {
            var $input = $('#cmw-password');
            var hidden = $input.attr('type') === 'password';
            $input.attr('type', hidden ? 'text' : 'password');
            $(this).text(hidden ? S.hide : S.show);
        });
        $('#cmw-password').on('input', function () {
            var value = $(this).val();
            $('#cmw-rule-len').toggleClass('is-ok', value.length >= 8);
            $('#cmw-rule-mix').toggleClass('is-ok', /[A-Za-z]/.test(value) && /[0-9]/.test(value));
        });
        $('#cmw-connect-form').on('submit', function (event) {
            event.preventDefault();
            submitConnect(mode);
        });
    }

    function field(id, label, type, name, value) {
        return '<div class="cmw-field"><label for="' + id + '">' + esc(label) + '</label><input id="' + id + '" name="' + name + '" type="' + type + '" class="regular-text" value="' + esc(value) + '" autocomplete="' + (name === 'email' ? 'email' : 'name') + '"></div>';
    }

    function submitConnect(mode) {
        var $error = $('#cmw-connect-error').prop('hidden', true).empty();
        var email = $.trim($('#cmw-email').val());
        var password = $('#cmw-password').val();
        var name = $.trim($('#cmw-name').val() || '');
        if (!email || !password || (mode === 'signup' && !name)) {
            $error.text(S.fillAll).prop('hidden', false);
            return;
        }
        if (mode === 'signup' && !$('#cmw-agree').is(':checked')) {
            $error.text(S.agreeRequired).prop('hidden', false);
            return;
        }
        var $button = $('#cmw-connect-submit');
        busy($button, mode === 'signup' ? S.creating : S.signingIn);
        var request;
        if (mode === 'signup') {
            request = post('chatmaxima_register', { name: name, email: email, password: password, workspace_name: C.site.name });
        } else {
            request = post('chatmaxima_login', { email: email, password: password });
        }
        request.done(function (data) {
            if (data && data.verification_required) {
                renderVerify(data.email || email, false);
                return;
            }
            saveStep('knowledge').always(function () { window.location.reload(); });
        }).fail(function (error) {
            unbusy($button);
            if (error.code === 'ACCOUNT_INACTIVE') {
                renderVerify(email, true);
                return;
            }
            if (error.code === 'EMAIL_EXISTS') {
                $('#cmw-email').addClass('has-error');
                $error.html(esc(S.emailTaken) + ' <button type="button" class="button button-small" id="cmw-signin-instead">' + esc(S.signInInstead) + '</button>').prop('hidden', false);
                $('#cmw-signin-instead').on('click', function () {
                    renderConnect('signin');
                    $('#cmw-email').val(email);
                    $('#cmw-password').trigger('focus');
                });
                return;
            }
            $error.text(error.message).prop('hidden', false);
        });
    }

    // A new account activates with the 6-digit code ChatMaxima emails
    function renderVerify(email, pending) {
        var html = '<h2 class="cmw-h">' + esc(S.verifyTitle) + '</h2><p class="cmw-intro">' + esc(pending ? S.verifyPending : fmt(S.verifyIntro, email)) + '</p>';
        html += '<form class="cmw-form" id="cmw-verify-form" novalidate><div class="cmw-field"><label for="cmw-code">' + esc(S.code) + '</label>';
        html += '<input id="cmw-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" class="cmw-code-input"></div>';
        html += '<p><button type="submit" class="button button-primary button-hero cmw-primary" id="cmw-verify-submit">' + esc(S.verifyContinue) + '</button></p>';
        html += '<p class="cmw-muted">' + esc(S.noCode) + ' <button type="button" class="cmw-link-btn" id="cmw-resend">' + esc(S.resendCode) + '</button>. <button type="button" class="cmw-link-btn" id="cmw-wrong-email">' + esc(S.wrongEmail) + '</button></p>';
        html += '<div id="cmw-verify-error" class="cmw-inline-error" role="alert" hidden></div></form>';
        renderShell(html);
        $('#cmw-code').trigger('focus').on('input', function () {
            var digits = $(this).val().replace(/[^0-9]/g, '').slice(0, 6);
            $(this).val(digits);
            if (digits.length === 6) {
                $('#cmw-verify-form').trigger('submit');
            }
        });
        $('#cmw-verify-form').on('submit', function (event) {
            event.preventDefault();
            var code = $('#cmw-code').val();
            var $error = $('#cmw-verify-error').prop('hidden', true);
            if (code.length !== 6) {
                return;
            }
            var $button = $('#cmw-verify-submit');
            busy($button, S.verifying);
            post('chatmaxima_wiz_verify_signup', { email: email, code: code }).done(function () {
                window.location.reload();
            }).fail(function (error) {
                unbusy($button);
                $error.text(error.message).prop('hidden', false);
                $('#cmw-code').val('').trigger('focus');
            });
        });
        $('#cmw-resend').on('click', function () {
            var $link = $(this).prop('disabled', true);
            post('chatmaxima_wiz_resend_code', { email: email }).done(function () {
                notice(S.codeSent, 'success');
            }).fail(function (error) {
                notice(error.message, 'error');
            }).always(function () {
                setTimeout(function () { $link.prop('disabled', false); }, 30000); // one resend per 30 seconds from this page
            });
        });
        $('#cmw-wrong-email').on('click', function () { renderConnect('signup'); });
    }

    // ---- 2. Workspace ----

    function loadTeams() {
        return post('chatmaxima_wiz_teams').done(function (data) {
            ui.teams = data.teams || [];
            if (!C.user.teamName) { // the sign-in response has the alias only; take the name from the list
                $.each(ui.teams, function (i, team) {
                    if (team.team_alias === C.user.teamAlias) {
                        C.user.teamName = team.team_name;
                    }
                });
                $('#cmw-workspace-name').text(C.user.teamName || '');
            }
        });
    }

    function siteMatches(team) {
        var name = String(team.team_name || '').toLowerCase();
        var site = String(C.site.name || '').toLowerCase();
        var host = String(C.site.host || '').toLowerCase().replace(/^www\./, '').split('.')[0];
        return (site && name === site) || (host && name.replace(/\s+/g, '').indexOf(host) !== -1);
    }

    function countLabel(count, one, many, none) {
        if (!count) {
            return none;
        }
        if (count === 1) {
            return one;
        }
        return fmt(many, count);
    }

    function renderWorkspace() {
        var teams = ui.teams || [];
        var selected = C.user.teamAlias;
        var matched = '';
        $.each(teams, function (i, team) {
            if (!matched && siteMatches(team)) {
                matched = team.team_alias;
            }
        });
        if (!C.state.workspace_confirmed && matched) {
            selected = matched;
        }
        var html = '<h2 class="cmw-h">' + esc(S.workspaceTitle) + '</h2><p class="cmw-intro">' + esc(fmt(S.workspaceIntro, teams.length)) + '</p>';
        html += '<div class="cmw-cards" role="radiogroup">';
        $.each(teams, function (i, team) {
            var checked = team.team_alias === selected;
            var meta = [];
            if (team.role) {
                meta.push(esc(team.role));
            }
            meta.push(esc(countLabel(team.bot_count, S.oneChatbot, S.nChatbots, S.noChatbots)));
            meta.push(esc(countLabel(team.channel_count, S.oneChannel, S.nChannels, S.noChannels)));
            html += '<label class="cmw-choice' + (checked ? ' is-selected' : '') + '"><input type="radio" name="cmw-team" value="' + esc(team.team_alias) + '"' + (checked ? ' checked' : '') + ' data-name="' + esc(team.team_name) + '">';
            html += '<span class="cmw-avatar">' + esc(initials(team.team_name)) + '</span><span class="cmw-choice-body"><span class="cmw-choice-title">' + esc(team.team_name);
            if (team.team_alias === matched) {
                html += ' <span class="cmw-badge">' + esc(S.matchesSite) + '</span>';
            }
            html += '</span><span class="cmw-choice-meta">' + meta.join('<span class="cmw-dot">·</span>') + '</span></span></label>';
        });
        html += '</div><p class="cmw-muted">' + esc(fmt(S.workspaceMissing, C.user.email)) + ' <a href="#" id="cmw-refresh-teams">' + esc(S.refreshList) + '</a>.</p>';
        html += footer(C.editing ? '<a class="button" href="' + esc(C.homeUrl) + '">' + esc(S.backHome) + '</a>' : '', '<button type="button" class="button button-primary cmw-primary" id="cmw-team-continue"></button>');
        renderShell(html);

        function updateButton() {
            var $radio = $app.find('input[name="cmw-team"]:checked');
            $('#cmw-team-continue').text(fmt(S.continueWith, $radio.data('name') || ''));
            $app.find('.cmw-choice').removeClass('is-selected');
            $radio.closest('.cmw-choice').addClass('is-selected');
        }
        updateButton();
        $app.find('input[name="cmw-team"]').on('change', updateButton);
        $('#cmw-refresh-teams').on('click', function (event) {
            event.preventDefault();
            loadTeams().done(renderWorkspace);
        });
        $('#cmw-team-continue').on('click', function () {
            var $button = $(this);
            busy($button, S.saving);
            post('chatmaxima_wiz_select_team', { team_alias: $app.find('input[name="cmw-team"]:checked').val() }).done(function () {
                if (C.editing) {
                    window.location.href = C.homeUrl;
                    return;
                }
                window.location.reload(); // new tokens and workspace details
            }).fail(function (error) {
                unbusy($button);
                notice(error.message, 'error');
            });
        });
    }

    // ---- 3. Knowledge ----

    function selectedTypes() {
        return C.selectedPostTypes || [];
    }

    function selectedCount() {
        var total = 0;
        $.each(C.postTypes || [], function (i, type) {
            if (selectedTypes().indexOf(type.name) !== -1) {
                total = total + type.count;
            }
        });
        return total;
    }

    function saveSyncSettings() {
        return post('chatmaxima_save_sync_settings', { post_types: selectedTypes(), auto_sync: C.autoSync ? '1' : '0' });
    }

    function renderKnowledge() {
        var html = '<h2 class="cmw-h">' + esc(S.knowledgeTitle) + '</h2><p class="cmw-intro">' + esc(S.knowledgeIntro) + '</p>';
        if (C.site.isLocal) {
            html += '<div class="cmw-inline-warning">' + esc(fmt(S.localSite, C.site.host)) + '</div>';
        }
        html += '<div class="cmw-section"><h3 class="cmw-h3">' + esc(S.knowledgeSource) + '</h3><div class="cmw-ks" id="cmw-ks"><span class="spinner is-active"></span></div></div>';
        html += '<div class="cmw-section"><h3 class="cmw-h3">' + esc(S.whatToSync) + '</h3><div class="cmw-chips" id="cmw-chips"></div><p class="cmw-muted" id="cmw-chip-summary"></p></div>';
        html += '<div class="cmw-section cmw-switch-row"><label class="cmw-switch"><input type="checkbox" id="cmw-auto-sync"' + (C.autoSync ? ' checked' : '') + '><span class="cmw-switch-ui" aria-hidden="true"></span><span class="cmw-switch-text"><strong>' + esc(S.autoSync) + '</strong><span>' + esc(S.autoSyncHelp) + '</span></span></label></div>';
        html += '<div class="cmw-section"><div class="cmw-section-head"><h3 class="cmw-h3">' + esc(S.sync) + '</h3><button type="button" class="button" id="cmw-sync-toggle"></button></div><div class="cmw-sync" id="cmw-sync"></div></div>';
        var right = '<span class="cmw-muted" id="cmw-continue-hint">' + esc(S.continueUnlocks) + '</span> <button type="button" class="button button-primary cmw-primary" id="cmw-knowledge-continue">' + esc(C.editing ? S.saveChanges : S.continueChatbot) + '</button>';
        var back = '';
        if (C.editing) {
            back = '<a class="button" href="' + esc(C.homeUrl) + '">' + esc(S.backHome) + '</a>';
        } else if (ui.workspaceNeeded) {
            back = '<button type="button" class="button" data-go="workspace">' + esc(S.back) + '</button>';
        }
        html += footer(back, right);
        renderShell(html);

        if (C.lastFullSync && C.lastFullSync.total && (C.lastFullSync.synced >= C.lastFullSync.total || C.lastFullSync.limit_reached) && C.knowledgeAlias && !ui.sync.running) {
            ui.sync.done = true;
            ui.sync.synced = C.lastFullSync.synced;
            ui.sync.total = C.lastFullSync.total;
            if (C.lastFullSync.limit_reached && C.planLimit) {
                ui.sync.limit = { limit: C.planLimit.limit, upgradeUrl: C.planLimit.upgrade_url };
            }
        }

        renderChips();
        renderSyncBox();
        loadKnowledgeSources();

        $app.find('[data-go]').on('click', function () { go($(this).data('go')); });
        $('#cmw-auto-sync').on('change', function () {
            C.autoSync = $(this).is(':checked');
            saveSyncSettings().fail(function (error) { notice(error.message, 'error'); });
        });
        $('#cmw-sync-toggle').on('click', function () {
            if (ui.sync.running) {
                ui.sync.paused = true;
                ui.sync.running = false;
                renderSyncBox();
                return;
            }
            startSync();
        });
        $('#cmw-knowledge-continue').on('click', function () {
            var $button = $(this);
            ensureKnowledgeSource().done(function () {
                if (C.editing) {
                    window.location.href = C.homeUrl;
                    return;
                }
                busy($button, S.saving);
                saveStep('chatbot').always(function () { unbusy($button); go('chatbot'); });
            });
        });
        updateContinue();
    }

    function updateContinue() {
        var ready = ui.sync.done || (ui.knowledge.mode === 'existing' && !ui.sync.running);
        $('#cmw-knowledge-continue').prop('disabled', !ready || ui.sync.running);
        $('#cmw-continue-hint').prop('hidden', ready);
    }

    function loadKnowledgeSources() {
        post('chatmaxima_get_knowledge_sources').done(function (data) {
            ui.knowledge.sources = data.knowledge_sources || [];
            var selected = data.selected || C.knowledgeAlias;
            ui.knowledge.inactive = !!data.selected_inactive;
            if (ui.knowledge.inactive) {
                // The stored source was deactivated in ChatMaxima: offer the active ones (or a new one) instead of syncing into it
                selected = '';
                ui.knowledge.mode = ui.knowledge.sources.length ? 'existing' : 'new';
                ui.knowledge.selected = ui.knowledge.sources.length ? ui.knowledge.sources[0].alias : '';
                ui.sync.done = false;
                renderSyncBox();
                updateContinue();
            }
            if (selected) {
                ui.knowledge.mode = 'existing';
                ui.knowledge.selected = selected;
            }
            renderKsChoice();
        }).fail(function (error) {
            ui.knowledge.sources = [];
            renderKsChoice();
            notice(error.message, 'error');
        });
    }

    function renderKsChoice() {
        var sources = ui.knowledge.sources || [];
        var isNew = ui.knowledge.mode === 'new';
        var html = '';
        if (ui.knowledge.inactive) {
            html += noteHtml(S.ksInactivePick);
        }
        if (ui.knowledge.limitUrl) {
            html += noteHtml(S.ksLimit, ui.knowledge.limitUrl, S.upgradePlan, true);
        }
        html += '<div class="cmw-ks-col"><label class="cmw-radio"><input type="radio" name="cmw-ks-mode" value="new"' + (isNew ? ' checked' : '') + '> ' + esc(S.createNewKs) + '</label>';
        html += '<div class="cmw-field cmw-indent"><label for="cmw-ks-name">' + esc(S.name) + '</label><input id="cmw-ks-name" type="text" class="regular-text" value="' + esc(ui.knowledge.name) + '"' + (isNew ? '' : ' disabled') + '><p class="cmw-help">' + esc(S.nameFromSite) + '</p></div></div>';
        html += '<div class="cmw-ks-col"><label class="cmw-radio"><input type="radio" name="cmw-ks-mode" value="existing"' + (isNew ? '' : ' checked') + (sources.length ? '' : ' disabled') + '> ' + esc(S.useExistingKs) + '</label>';
        html += '<div class="cmw-indent"><select id="cmw-ks-select"' + (isNew || !sources.length ? ' disabled' : '') + '>';
        if (!sources.length) {
            html += '<option value="">' + esc(S.noExistingKs) + '</option>';
        }
        $.each(sources, function (i, source) {
            html += '<option value="' + esc(source.alias) + '"' + (source.alias === ui.knowledge.selected ? ' selected' : '') + '>' + esc(source.name) + ' · ' + esc(fmt(S.nDocuments, source.document_count || 0)) + '</option>';
        });
        html += '</select></div></div>';
        $('#cmw-ks').html(html);
        $('#cmw-ks').find('input[name="cmw-ks-mode"]').on('change', function () {
            ui.knowledge.mode = $(this).val();
            if (ui.knowledge.mode === 'existing' && !ui.knowledge.selected && sources.length) {
                ui.knowledge.selected = sources[0].alias;
            }
            ui.sync.done = false;
            renderKsChoice();
            renderSyncBox();
            updateContinue();
        });
        $('#cmw-ks-name').on('input', function () { ui.knowledge.name = $(this).val(); });
        $('#cmw-ks-select').on('change', function () {
            ui.knowledge.selected = $(this).val();
            ui.sync.done = false;
            renderSyncBox();
            updateContinue();
        });
    }

    // Create the new knowledge source or save the chosen one, once
    function ensureKnowledgeSource() {
        var deferred = $.Deferred();
        if (ui.knowledge.mode === 'existing') {
            if (ui.knowledge.selected === C.knowledgeAlias) {
                return deferred.resolve().promise();
            }
            post('chatmaxima_save_ks_selection', { alias: ui.knowledge.selected }).done(function () {
                C.knowledgeAlias = ui.knowledge.selected;
                ui.knowledge.inactive = false;
                renderKsChoice();
                deferred.resolve();
            }).fail(function (error) {
                notice(error.message, 'error');
                deferred.reject();
            });
            return deferred.promise();
        }
        var name = $.trim(ui.knowledge.name) || C.site.name;
        post('chatmaxima_create_knowledge_source', { name: name, llm_type: 'openai' }).done(function (data) {
            C.knowledgeAlias = data.knowledge_source.knowledge_source_alias;
            ui.knowledge.mode = 'existing';
            ui.knowledge.selected = C.knowledgeAlias;
            ui.knowledge.sources = (ui.knowledge.sources || []).concat([{ alias: C.knowledgeAlias, name: name, document_count: 0 }]);
            ui.knowledge.inactive = false;
            ui.knowledge.limitUrl = '';
            renderKsChoice();
            deferred.resolve();
        }).fail(function (error) {
            if (error.code === 'PLAN_KNOWLEDGE_SOURCE_LIMIT') {
                // Free plan: one active source. Point at the existing one and the upgrade page
                ui.knowledge.limitUrl = error.upgradeUrl || 'https://chatmaxima.com/dashboard/plans/';
                if ((ui.knowledge.sources || []).length) {
                    ui.knowledge.mode = 'existing';
                    ui.knowledge.selected = ui.knowledge.sources[0].alias;
                }
                renderKsChoice();
                updateContinue();
            } else {
                notice(error.message, 'error');
            }
            deferred.reject();
        });
        return deferred.promise();
    }

    function renderChips() {
        var html = '';
        $.each(C.postTypes || [], function (i, type) {
            var on = selectedTypes().indexOf(type.name) !== -1;
            html += '<button type="button" class="cmw-chip' + (on ? ' is-on' : '') + '" data-type="' + esc(type.name) + '" aria-pressed="' + on + '"' + (ui.sync.running ? ' disabled' : '') + '>';
            if (on) {
                html += '<span class="dashicons dashicons-yes" aria-hidden="true"></span>';
            }
            html += esc(type.label) + ' <span class="cmw-chip-count">' + esc(type.count) + '</span></button>';
        });
        $('#cmw-chips').html(html);
        $('#cmw-chip-summary').html('<strong>' + esc(fmt(S.itemsSelected, selectedCount())) + '</strong> ' + esc(S.onlyPublished));
        $('#cmw-chips').find('.cmw-chip').on('click', function () {
            var type = $(this).data('type');
            var list = selectedTypes().slice();
            var position = list.indexOf(type);
            if (position === -1) {
                list.push(type);
            } else {
                list.splice(position, 1);
            }
            C.selectedPostTypes = list;
            ui.sync.done = false;
            renderChips();
            renderSyncBox();
            updateContinue();
            saveSyncSettings().fail(function (error) { notice(error.message, 'error'); });
        });
    }

    function planLimitHtml(limit, upgradeUrl) {
        return '<div class="cmw-limit-note"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' + esc(fmt(S.planLimitNote, limit || 100)) +
            '</span> <a class="button button-small" href="' + esc(safeUrl(upgradeUrl || 'https://chatmaxima.com/dashboard/plans/')) + '" target="_blank" rel="noopener noreferrer">' + esc(S.upgradePlan) + '</a></div>';
    }

    // A warning line with an optional button, the same look as the plan limit note
    function noteHtml(message, linkUrl, linkLabel, external) {
        var html = '<div class="cmw-limit-note"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' + esc(message) + '</span>';
        if (linkUrl) {
            var href = linkUrl; // our own admin page
            if (external) {
                href = safeUrl(linkUrl); // a URL the ChatMaxima API sent back
            }
            html += ' <a class="button button-small" href="' + esc(href) + '"' + (external ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + esc(linkLabel) + '</a>';
        }
        return html + '</div>';
    }

    function renderSyncBox() {
        var sync = ui.sync;
        var total = sync.total || selectedCount();
        var percent = 0;
        if (total > 0) {
            percent = Math.min(100, Math.round((sync.synced / total) * 100));
        }
        var html = '';
        var $toggle = $('#cmw-sync-toggle');
        if (!selectedCount()) {
            html = '<p class="cmw-muted">' + esc(S.syncNothing) + '</p>';
            $toggle.prop('hidden', true);
        } else if (sync.done && sync.limit) {
            html = '<div class="cmw-sync-title"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' + esc(fmt(S.syncLimited, sync.synced, sync.total || total)) + '</div>';
            html += planLimitHtml(sync.limit.limit, sync.limit.upgradeUrl);
            html += '<p class="cmw-muted"><strong>' + esc(S.readStatus) + ':</strong> <span id="cmw-sync-read"><span class="spinner is-active"></span></span></p>';
            $toggle.prop('hidden', false).text(S.syncNow);
        } else if (sync.done) {
            html = '<div class="cmw-sync-title is-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' + esc(fmt(S.syncDone, sync.total || total)) + '</div>';
            html += '<p class="cmw-muted"><strong>' + esc(S.readStatus) + ':</strong> <span id="cmw-sync-read"><span class="spinner is-active"></span></span></p>';
            $toggle.prop('hidden', false).text(S.syncNow);
        } else if (sync.running || sync.paused || sync.synced) {
            var title = fmt(sync.paused ? S.pausedOf : S.syncingOf, sync.synced, total);
            var batches = Math.max(1, Math.ceil(total / 10));
            var batch = Math.min(batches, Math.floor(sync.synced / 10) + 1);
            html = '<div class="cmw-sync-head"><span class="cmw-sync-title">' + esc(title) + '</span><span class="cmw-muted">' + esc(fmt(S.batchOf, batch, batches)) + '</span></div>';
            html += '<div class="cmw-progress"><span style="width:' + percent + '%"></span></div>';
            if (sync.items.length) {
                html += '<ul class="cmw-sync-items">';
                $.each(sync.items.slice(-3), function (i, item) {
                    html += '<li><span class="dashicons dashicons-yes" aria-hidden="true"></span>' + esc(item.title) + ' <span class="cmw-muted">' + esc(item.type) + '</span></li>';
                });
                html += '</ul>';
            }
            if (sync.error) {
                html += '<div class="cmw-inline-error">' + esc(S.syncFailed) + ' ' + esc(sync.error) + '</div>';
            }
            html += '<p class="cmw-muted">' + esc(S.syncKeepOpen) + '</p>';
            $toggle.prop('hidden', false).text(sync.running ? S.pause : (sync.error ? S.retry : S.resume));
        } else {
            html = '<p class="cmw-muted">' + esc(S.syncReady) + '</p>';
            $toggle.prop('hidden', false).text(S.startSync);
        }
        $('#cmw-sync').html(html);
        $toggle.toggleClass('button-primary', !sync.running && !sync.done);
        if (sync.done) {
            loadReadStatus($('#cmw-sync-read'));
        }
    }

    function startSync() {
        if (!selectedCount()) {
            return;
        }
        ensureKnowledgeSource().done(function () {
            if (ui.sync.done) {
                ui.sync.done = false;
                ui.sync.synced = 0;
                ui.sync.offset = 0;
                ui.sync.items = [];
                ui.sync.limit = null;
            }
            ui.sync.running = true;
            ui.sync.paused = false;
            ui.sync.error = '';
            renderChips();
            renderSyncBox();
            updateContinue();
            syncBatch();
        });
    }

    function syncBatch() {
        if (!ui.sync.running) {
            renderChips();
            updateContinue();
            return;
        }
        post('chatmaxima_sync_posts', { post_types: selectedTypes(), offset: ui.sync.offset }).done(function (data) {
            ui.sync.total = data.total || 0;
            ui.sync.synced = data.synced || 0;
            ui.sync.offset = data.next_offset || data.synced || 0;
            ui.sync.items = ui.sync.items.concat(data.items || []).slice(-6);
            if (data.complete) {
                ui.sync.running = false;
                ui.sync.done = true;
                ui.sync.limit = null;
                if (data.limit_reached) {
                    ui.sync.limit = { limit: data.limit, upgradeUrl: data.upgrade_url };
                }
                C.lastFullSync = { synced: ui.sync.synced, total: ui.sync.total, limit_reached: !!data.limit_reached };
                renderChips();
                renderSyncBox();
                updateContinue();
                return;
            }
            renderSyncBox();
            syncBatch();
        }).fail(function (error) {
            ui.sync.running = false;
            ui.sync.paused = true;
            ui.sync.error = error.message;
            renderChips();
            renderSyncBox();
            updateContinue();
            if (error.code === 'KNOWLEDGE_SOURCE_INACTIVE') {
                loadKnowledgeSources(); // deactivated in ChatMaxima mid-sync: offer the active sources
            }
        });
    }

    // ---- 4. Chatbot ----

    var ROLES = [
        { key: 'support', purpose: 'answer_queries', title: 'roleSupport', sub: 'roleSupportSub' },
        { key: 'sales', purpose: 'sell_products', title: 'roleSales', sub: 'roleSalesSub' },
        { key: 'booking', purpose: 'book_appointments', title: 'roleBooking', sub: 'roleBookingSub' },
        { key: 'other', purpose: 'custom', title: 'roleOther', sub: 'roleOtherSub' }
    ];

    function personaFromBot() {
        if (ui.persona) {
            return ui.persona;
        }
        var bot = ui.bot || {};
        var role = 'support';
        if (bot.purpose === 'sell_products') {
            role = 'sales';
        } else if (bot.purpose === 'book_appointments') {
            role = 'booking';
        } else if (bot.purpose === 'custom' || bot.business_description) {
            role = 'other'; // custom: the description is the bot's job; older bots kept it as answer_queries plus a description
        }
        var name = bot.name || 'Max';
        ui.persona = {
            name: name,
            role: role,
            tone: bot.tone || 'friendly',
            description: bot.business_description || '',
            welcome: bot.welcome_message || fmt(S.welcomeDefault, name, C.site.name),
            welcomeTouched: !!bot.welcome_message,
            instructions: bot.instructions || '',
            avatarUrl: bot.avatar_url || '',
            avatarChoice: bot.avatar_url ? 'custom' : 'initial'
        };
        if (bot.model && bot.model.provider) {
            ui.model.provider = bot.model.provider;
            ui.model.model = bot.model.model || '';
            ui.model.credentialId = bot.model.credential_id || '';
        }
        return ui.persona;
    }

    function renderChatbot() {
        var p = personaFromBot();
        var html = '<h2 class="cmw-h">' + esc(S.chatbotTitle) + '</h2><p class="cmw-intro">' + esc(S.chatbotIntro) + '</p>';
        html += '<div class="cmw-tabs" role="tablist"><button type="button" role="tab" class="cmw-tab' + (ui.chatbotTab === 'persona' ? ' is-active' : '') + '" data-tab="persona">' + esc(S.tabPersona) + (ui.bot && ui.chatbotTab === 'model' ? ' <span class="dashicons dashicons-yes" aria-hidden="true"></span>' : '') + '</button><button type="button" role="tab" class="cmw-tab' + (ui.chatbotTab === 'model' ? ' is-active' : '') + '" data-tab="model"' + (ui.bot ? '' : ' disabled') + '>' + esc(S.tabModel) + '</button></div>';
        html += '<div id="cmw-tab-body"></div>';
        renderShell(html);
        $app.find('.cmw-tab').on('click', function () {
            var tab = $(this).data('tab');
            if (tab === 'model' && !ui.bot) {
                return;
            }
            readPersona();
            ui.chatbotTab = tab;
            renderChatbot();
        });
        if (ui.chatbotTab === 'model') {
            renderModelTab();
        } else {
            renderPersonaTab(p);
        }
    }

    function renderPersonaTab(p) {
        var html = '<div class="cmw-cols cmw-cols-wide"><div class="cmw-form">';
        html += '<div class="cmw-row"><div class="cmw-field"><label for="cmw-bot-name">' + esc(S.botName) + '</label><input id="cmw-bot-name" type="text" maxlength="60" class="regular-text" value="' + esc(p.name) + '"></div>';
        html += '<div class="cmw-field"><span class="cmw-label">' + esc(S.avatar) + '</span><div class="cmw-avatars" role="radiogroup">';
        html += '<button type="button" class="cmw-avatar-opt' + (p.avatarChoice === 'initial' ? ' is-on' : '') + '" data-avatar="initial" title="' + esc(S.avatarInitial) + '"><span class="cmw-avatar">' + esc(initials(p.name)) + '</span></button>';
        if (C.site.iconUrl) {
            html += '<button type="button" class="cmw-avatar-opt' + (p.avatarChoice === 'site_icon' ? ' is-on' : '') + '" data-avatar="site_icon" title="' + esc(S.avatarSiteIcon) + '"><img src="' + esc(safeUrl(C.site.iconUrl)) + '" alt="' + esc(S.avatarSiteIcon) + '"></button>';
        }
        if (p.avatarChoice === 'custom' && safeUrl(p.avatarUrl)) {
            html += '<button type="button" class="cmw-avatar-opt is-on" data-avatar="custom"><img src="' + esc(safeUrl(p.avatarUrl)) + '" alt=""></button>';
        }
        html += '<label class="button cmw-upload">' + esc(S.upload) + '<input type="file" id="cmw-avatar-file" accept="image/png,image/jpeg,image/webp,image/gif" hidden></label></div></div></div>';
        html += '<div class="cmw-field"><span class="cmw-label" id="cmw-role-label">' + esc(fmt(S.whatShouldDo, p.name)) + '</span><div class="cmw-role-grid" role="radiogroup">';
        $.each(ROLES, function (i, role) {
            html += '<label class="cmw-choice cmw-choice-sm' + (p.role === role.key ? ' is-selected' : '') + '"><input type="radio" name="cmw-role" value="' + role.key + '"' + (p.role === role.key ? ' checked' : '') + '><span class="cmw-choice-body"><span class="cmw-choice-title">' + esc(S[role.title]) + '</span><span class="cmw-choice-meta">' + esc(S[role.sub]) + '</span></span></label>';
        });
        html += '</div><textarea id="cmw-role-other" rows="2" maxlength="1000" placeholder="' + esc(S.roleOtherPlaceholder) + '"' + (p.role === 'other' ? '' : ' hidden') + '>' + esc(p.description) + '</textarea></div>';
        html += '<div class="cmw-field"><span class="cmw-label">' + esc(S.tone) + '</span><div class="cmw-segment">';
        $.each(['friendly', 'professional', 'concise'], function (i, tone) {
            var label = S['tone' + tone.charAt(0).toUpperCase() + tone.slice(1)];
            html += '<button type="button" class="cmw-seg' + (p.tone === tone ? ' is-active' : '') + '" data-tone="' + tone + '" aria-pressed="' + (p.tone === tone) + '">' + esc(label) + '</button>';
        });
        html += '</div></div>';
        html += '<div class="cmw-field"><label for="cmw-welcome">' + esc(S.welcomeMessage) + '</label><textarea id="cmw-welcome" rows="2" maxlength="1000">' + esc(p.welcome) + '</textarea><p class="cmw-help">' + esc(S.welcomeHelp) + '</p></div>';
        html += '<div class="cmw-field"><div class="cmw-label-row"><label for="cmw-instructions">' + esc(S.extraInstructions) + ' <span class="cmw-muted">' + esc(S.optional) + '</span></label><span class="cmw-muted" id="cmw-instr-count">' + p.instructions.length + ' / 2000</span></div><textarea id="cmw-instructions" rows="3" maxlength="2000">' + esc(p.instructions) + '</textarea><p class="cmw-help">' + esc(S.instructionsHelp) + '</p></div>';
        html += '</div><div class="cmw-preview-card" id="cmw-preview"></div></div>';
        var back = C.editing ? '<a class="button" href="' + esc(C.homeUrl) + '">' + esc(S.backHome) + '</a>' : '<button type="button" class="button" id="cmw-chatbot-back">' + esc(S.back) + '</button>';
        html += footer(back, '<button type="button" class="button button-primary cmw-primary" id="cmw-persona-next">' + esc(C.editing ? S.saveChanges : S.nextModel) + ' <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>');
        $('#cmw-tab-body').html(html);
        renderPreview();

        $('#cmw-bot-name').on('input', function () {
            var name = $(this).val();
            if (!ui.persona.welcomeTouched) {
                $('#cmw-welcome').val(fmt(S.welcomeDefault, name || 'Max', C.site.name));
            }
            $('#cmw-role-label').text(fmt(S.whatShouldDo, name || 'Max'));
            $app.find('[data-avatar="initial"] .cmw-avatar').text(initials(name));
        });
        $('#cmw-welcome').on('input', function () { ui.persona.welcomeTouched = true; });
        $('#cmw-instructions').on('input', function () { $('#cmw-instr-count').text($(this).val().length + ' / 2000'); });
        $app.find('input[name="cmw-role"]').on('change', function () {
            $app.find('.cmw-role-grid .cmw-choice').removeClass('is-selected');
            $(this).closest('.cmw-choice').addClass('is-selected');
            $('#cmw-role-other').prop('hidden', $(this).val() !== 'other');
        });
        $app.find('[data-tone]').on('click', function () {
            $app.find('[data-tone]').removeClass('is-active').attr('aria-pressed', 'false');
            $(this).addClass('is-active').attr('aria-pressed', 'true');
        });
        $app.find('[data-avatar]').on('click', function () {
            var choice = $(this).data('avatar');
            readPersona();
            if (choice === 'site_icon') {
                uploadAvatar({ source: 'site_icon' }, null);
                return;
            }
            ui.persona.avatarChoice = choice;
            if (choice === 'initial') {
                ui.persona.avatarUrl = '';
            }
            renderPersonaTab(ui.persona);
        });
        $('#cmw-avatar-file').on('change', function () {
            if (this.files && this.files[0]) {
                readPersona();
                uploadAvatar({ source: 'file' }, { avatar: this.files[0] });
            }
        });
        $('#cmw-chatbot-back').on('click', function () { go('knowledge'); });
        $('#cmw-persona-next').on('click', function () {
            var $button = $(this);
            savePersona($button).done(function () {
                if (C.editing) {
                    window.location.href = C.homeUrl;
                    return;
                }
                ui.chatbotTab = 'model';
                renderChatbot();
            });
        });
    }

    function uploadAvatar(data, files) {
        notice(S.uploading, 'info');
        post('chatmaxima_wiz_avatar', data, files).done(function (result) {
            notice('');
            ui.persona.avatarUrl = result.avatar_url;
            ui.persona.avatarChoice = data.source === 'site_icon' ? 'site_icon' : 'custom';
            renderPersonaTab(ui.persona);
        }).fail(function (error) {
            notice(error.message, 'error');
        });
    }

    function readPersona() {
        if (!$('#cmw-bot-name').length) {
            return ui.persona;
        }
        var p = ui.persona;
        p.name = $.trim($('#cmw-bot-name').val()) || 'Max';
        p.role = $app.find('input[name="cmw-role"]:checked').val() || 'support';
        p.tone = $app.find('[data-tone].is-active').data('tone') || 'friendly';
        p.description = $.trim($('#cmw-role-other').val() || '');
        p.welcome = $.trim($('#cmw-welcome').val());
        p.instructions = $.trim($('#cmw-instructions').val());
        return p;
    }

    function personaPayload() {
        var p = readPersona();
        var purpose = 'answer_queries';
        $.each(ROLES, function (i, role) {
            if (role.key === p.role) {
                purpose = role.purpose;
            }
        });
        var description = '';
        if (p.role === 'other') {
            description = p.description;
        }
        return {
            name: p.name,
            purpose: purpose,
            tone: p.tone,
            business_description: description,
            welcome_message: p.welcome,
            instructions: p.instructions,
            avatar_url: p.avatarUrl || ''
        };
    }

    function savePersona($button) {
        var p = readPersona();
        if (p.role === 'other' && p.description === '') {
            $('#cmw-role-other').addClass('has-error').trigger('focus');
            notice(S.roleOtherRequired, 'error');
            return $.Deferred().reject({ message: S.roleOtherRequired }).promise();
        }
        $('#cmw-role-other').removeClass('has-error');
        busy($button, ui.bot ? S.saving : S.writingPrompt);
        return post('chatmaxima_wiz_save_bot', personaPayload()).done(function (data) {
            ui.bot = data.bot;
            ui.botLoaded = true;
            C.state = data.state;
            unbusy($button);
            renderPreview();
        }).fail(function (error) {
            unbusy($button);
            notice(error.message, 'error');
        });
    }

    function renderPreview() {
        var name = botDisplayName();
        var html = '<div class="cmw-preview-head"><h3 class="cmw-h3">' + esc(fmt(S.howWillSound, name)) + '</h3>';
        if (ui.bot) {
            html += '<button type="button" class="button button-small" id="cmw-preview-again"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' + esc(S.tryAgain) + '</button>';
        }
        html += '</div>';
        if (!ui.bot) {
            html += '<p class="cmw-muted">' + esc(S.previewSaveFirst) + '</p><button type="button" class="button" id="cmw-preview-save">' + esc(S.previewButton) + '</button>';
            $('#cmw-preview').html(html);
            $('#cmw-preview-save').on('click', function () {
                savePersona($(this)).done(function () { runPreview(S.sampleQ1); });
            });
            return;
        }
        html += '<div class="cmw-sample-chips">';
        $.each([S.sampleQ1, S.sampleQ2, S.sampleQ3], function (i, question) {
            html += '<button type="button" class="cmw-chip cmw-chip-sm' + (ui.preview.question === question ? ' is-on' : '') + '" data-question="' + esc(question) + '">' + esc(question) + '</button>';
        });
        html += '</div><div class="cmw-chat">';
        if (ui.preview.busy) {
            html += '<div class="cmw-bubble cmw-bubble-user">' + esc(ui.preview.question) + '</div><div class="cmw-bubble cmw-bubble-bot cmw-typing">' + esc(S.previewThinking) + '</div>';
        } else if (ui.preview.question) {
            html += '<div class="cmw-bubble cmw-bubble-user">' + esc(ui.preview.question) + '</div>';
            html += '<div class="cmw-bubble-row">' + avatarHtml() + '<div class="cmw-bubble cmw-bubble-bot">' + esc(ui.preview.answer || S.previewNoAnswer).replace(/\n/g, '<br>') + '</div></div>';
        } else {
            html += '<div class="cmw-bubble-row">' + avatarHtml() + '<div class="cmw-bubble cmw-bubble-bot">' + esc(ui.bot.welcome_message || '') + '</div></div>';
        }
        html += '</div><form class="cmw-ask" id="cmw-ask"><input type="text" id="cmw-ask-input" placeholder="' + esc(S.askPreview) + '" maxlength="500"><button type="submit" class="button">' + esc(S.previewButton) + '</button></form>';
        html += '<p class="cmw-help">' + esc(S.previewHelp) + '</p>';
        $('#cmw-preview').html(html);
        $('#cmw-preview').find('[data-question]').on('click', function () { runPreview($(this).data('question')); });
        $('#cmw-preview-again').on('click', function () { runPreview(ui.preview.question || S.sampleQ1); });
        $('#cmw-ask').on('submit', function (event) {
            event.preventDefault();
            var question = $.trim($('#cmw-ask-input').val());
            if (question) {
                runPreview(question);
            }
        });
    }

    function avatarHtml() {
        var p = ui.persona || {};
        if (safeUrl(p.avatarUrl)) {
            return '<img class="cmw-avatar cmw-avatar-sm" src="' + esc(safeUrl(p.avatarUrl)) + '" alt="">';
        }
        return '<span class="cmw-avatar cmw-avatar-sm">' + esc(initials(botDisplayName())) + '</span>';
    }

    function runPreview(question) {
        if (ui.preview.busy) {
            return;
        }
        ui.preview.busy = true;
        ui.preview.question = question;
        renderPreview();
        post('chatmaxima_wiz_preview', { question: question }).done(function (data) {
            ui.preview.answer = data.answer || '';
        }).fail(function (error) {
            ui.preview.answer = error.message;
        }).always(function () {
            ui.preview.busy = false;
            renderPreview();
        });
    }

    var PROVIDERS = [
        { key: 'openai', name: 'OpenAI', short: 'OA', sub: 'providerOpenai', keyUrl: 'https://platform.openai.com/api-keys' },
        { key: 'anthropic', name: 'Anthropic', short: 'AN', sub: 'providerAnthropic', keyUrl: 'https://console.anthropic.com/settings/keys' },
        { key: 'gemini', name: 'Google', short: 'G', sub: 'providerGemini', keyUrl: 'https://aistudio.google.com/app/apikey' },
        { key: 'groq', name: 'Groq', short: 'GQ', sub: 'providerGroq', keyUrl: 'https://console.groq.com/keys' }
    ];

    function providerInfo(key) {
        var found = null;
        $.each(PROVIDERS, function (i, provider) {
            if (provider.key === key) {
                found = provider;
            }
        });
        return found;
    }

    function renderModelTab() {
        var m = ui.model;
        var html = '<div class="cmw-cols cmw-cols-wide"><div>';
        html += '<label class="cmw-choice cmw-choice-hero' + (m.provider === 'chatmaxima' ? ' is-selected' : '') + '"><input type="radio" name="cmw-provider" value="chatmaxima"' + (m.provider === 'chatmaxima' ? ' checked' : '') + '>';
        html += '<img class="cmw-choice-logo" src="' + esc(C.pluginIcon || '') + '" alt="" hidden><span class="cmw-choice-body"><span class="cmw-choice-title">' + esc(S.maxiaTitle) + ' <span class="cmw-badge">' + esc(S.recommended) + '</span> <span class="cmw-badge cmw-badge-ok">' + esc(S.freeIncluded) + '</span></span><span class="cmw-choice-meta">' + esc(S.maxiaDesc) + '</span></span></label>';
        html += '<div class="cmw-divider"><span>' + esc(S.orOwnKey) + '</span></div><div class="cmw-provider-grid">';
        $.each(PROVIDERS, function (i, provider) {
            var on = m.provider === provider.key;
            html += '<label class="cmw-choice cmw-choice-sm' + (on ? ' is-selected' : '') + '"><input type="radio" name="cmw-provider" value="' + provider.key + '"' + (on ? ' checked' : '') + '><span class="cmw-avatar">' + esc(provider.short) + '</span><span class="cmw-choice-body"><span class="cmw-choice-title">' + esc(provider.name) + '</span><span class="cmw-choice-meta">' + esc(S[provider.sub]) + '</span></span></label>';
        });
        html += '</div><div id="cmw-key-box"></div></div>';
        html += '<div class="cmw-aside-box"><h3>' + esc(S.needKeyTitle) + '</h3><p>' + esc(S.needKey1) + '</p><p>' + esc(S.needKey2) + '</p><p>' + esc(S.needKey3) + '</p></div></div>';
        html += footer('<button type="button" class="button" id="cmw-model-back">' + esc(S.backPersona) + '</button>', '<button type="button" class="button button-primary cmw-primary" id="cmw-model-next">' + esc(C.editing ? S.saveChanges : S.continueInstall) + ' <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>');
        $('#cmw-tab-body').html(html);

        if (m.provider !== 'chatmaxima') {
            renderKeyBox();
        }
        $app.find('input[name="cmw-provider"]').on('change', function () {
            m.provider = $(this).val();
            m.keyTested = false;
            m.models = [];
            m.model = m.provider === 'chatmaxima' ? 'maxia-v4' : '';
            m.credentialId = '';
            renderModelTab();
        });
        $('#cmw-model-back').on('click', function () {
            ui.chatbotTab = 'persona';
            renderChatbot();
        });
        $('#cmw-model-next').on('click', function () { saveModel($(this)); });
    }

    function renderKeyBox() {
        var m = ui.model;
        var provider = providerInfo(m.provider);
        $('#cmw-key-box').html('<div class="cmw-key-box"><span class="spinner is-active"></span></div>');
        post('chatmaxima_wiz_keys').done(function (data) {
            m.keys = $.grep(data.keys || [], function (key) { return key.provider === m.provider; });
            if (!m.credentialId && m.keys.length) {
                m.credentialId = m.keys[0].credential_id;
            }
            drawKeyBox(provider);
            if (m.credentialId && !m.keyTested) {
                testKey(provider);
            }
        }).fail(function (error) {
            m.keys = [];
            drawKeyBox(provider);
            notice(error.message, 'error');
        });
    }

    function drawKeyBox(provider) {
        var m = ui.model;
        var html = '<div class="cmw-key-box">';
        if (!C.isSsl) {
            html += '<div class="cmw-inline-warning">' + esc(S.noSsl) + '</div>';
        }
        if (m.keys.length) {
            html += '<div class="cmw-field"><label for="cmw-key-saved">' + esc(S.savedKey) + '</label><select id="cmw-key-saved">';
            $.each(m.keys, function (i, key) {
                html += '<option value="' + esc(key.credential_id) + '"' + (key.credential_id === m.credentialId ? ' selected' : '') + '>' + esc(key.name) + '</option>';
            });
            html += '<option value=""' + (m.credentialId ? '' : ' selected') + '>' + esc(S.newKey) + '</option></select>';
            if (m.credentialId) {
                html += ' <button type="button" class="cmw-link-btn cmw-danger" id="cmw-key-remove">' + esc(S.removeKey) + '</button>';
            }
            html += '</div>';
        }
        if (!m.credentialId) {
            html += '<div class="cmw-label-row"><label for="cmw-key-input">' + esc(fmt(S.providerKey, provider.name)) + '</label><a href="' + esc(safeUrl(provider.keyUrl)) + '" target="_blank" rel="noopener noreferrer">' + esc(fmt(S.getKey, provider.name)) + '</a></div>';
            html += '<div class="cmw-key-row"><div class="cmw-password"><input type="password" id="cmw-key-input" autocomplete="off" spellcheck="false"><button type="button" class="cmw-link-btn" id="cmw-key-show">' + esc(S.show) + '</button></div><button type="button" class="button" id="cmw-key-test">' + esc(S.testKey) + '</button></div>';
        }
        html += '<div id="cmw-key-status" aria-live="polite"></div>';
        html += '<div class="cmw-field" id="cmw-model-field"' + (m.keyTested ? '' : ' hidden') + '><label for="cmw-model-select">' + esc(S.model) + '</label><select id="cmw-model-select">';
        $.each(m.models, function (i, model) {
            html += '<option value="' + esc(model.model) + '"' + (model.model === m.model ? ' selected' : '') + '>' + esc(model.name) + '</option>';
        });
        html += '</select><p class="cmw-help">' + esc(S.onlyKeyModels) + '</p></div>';
        html += '<div class="cmw-lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><strong>' + esc(S.keyKeptTitle) + '</strong> ' + esc(fmt(S.keyKept, C.user.teamName)) + '</span></div></div>';
        $('#cmw-key-box').html(html);

        $('#cmw-key-saved').on('change', function () {
            m.credentialId = $(this).val();
            m.keyTested = false;
            m.models = [];
            drawKeyBox(provider);
            if (m.credentialId) {
                testKey(provider);
            }
        });
        $('#cmw-key-show').on('click', function () {
            var $input = $('#cmw-key-input');
            var hidden = $input.attr('type') === 'password';
            $input.attr('type', hidden ? 'text' : 'password');
            $(this).text(hidden ? S.hide : S.show);
        });
        $('#cmw-key-input').on('input', function () {
            m.keyTested = false;
            $('#cmw-model-field').prop('hidden', true);
            $('#cmw-key-status').empty();
        });
        $('#cmw-key-test').on('click', function () { testKey(provider); });
        $('#cmw-model-select').on('change', function () { m.model = $(this).val(); });
        $('#cmw-key-remove').on('click', function () {
            var credential = m.credentialId;
            confirmBox(S.removeKey + '?', function () {
                post('chatmaxima_wiz_key_delete', { credential_id: credential }).done(function () {
                    m.credentialId = '';
                    m.keyTested = false;
                    renderKeyBox();
                }).fail(function (error) { notice(error.message, 'error'); });
            });
        });
    }

    function testKey(provider) {
        var m = ui.model;
        var data = { provider: m.provider };
        if (m.credentialId) {
            data.credential_id = m.credentialId;
        } else {
            data.api_key = $.trim($('#cmw-key-input').val());
            if (!data.api_key) {
                return;
            }
        }
        var $button = $('#cmw-key-test');
        if ($button.length) {
            busy($button, S.testing);
        }
        $('#cmw-key-status').html('<span class="spinner is-active"></span>');
        post('chatmaxima_wiz_key_test', data).done(function (result) {
            if (result.valid) {
                m.keyTested = true;
                m.models = result.models || [];
                if (!m.model || !$.grep(m.models, function (model) { return model.model === m.model; }).length) {
                    m.model = m.models.length ? m.models[0].model : '';
                }
                drawKeyBox(provider);
                if (!m.credentialId) {
                    $('#cmw-key-input').val(data.api_key);
                }
                $('#cmw-key-status').html('<p class="cmw-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' + esc(fmt(S.keyWorks, provider.name)) + '</p>');
                return;
            }
            m.keyTested = false;
            var message = result.state === 'unreachable' ? S.keyUnreachable : S.keyRejected;
            $('#cmw-key-status').html('<p class="cmw-inline-error">' + esc(fmt(message, provider.name)) + '</p>');
        }).fail(function (error) {
            $('#cmw-key-status').html('<p class="cmw-inline-error">' + esc(error.message) + '</p>');
        }).always(function () {
            if ($button.length) {
                unbusy($button);
            }
        });
    }

    function saveModel($button) {
        var m = ui.model;
        var finish = function () {
            busy($button, S.saving);
            post('chatmaxima_wiz_save_bot', { provider: m.provider, model: m.model, credential_id: m.credentialId }).done(function (data) {
                ui.bot = data.bot;
                C.state = data.state;
                if (C.editing) {
                    window.location.href = C.homeUrl;
                    return;
                }
                saveStep('install').always(function () { go('install'); });
            }).fail(function (error) {
                unbusy($button);
                notice(error.message, 'error');
            });
        };
        if (m.provider === 'chatmaxima') {
            m.model = 'maxia-v4';
            m.credentialId = '';
            finish();
            return;
        }
        if (!m.keyTested || !m.model) {
            notice(S.testKeyFirst, 'warning');
            return;
        }
        if (m.credentialId) {
            finish();
            return;
        }
        busy($button, S.saving);
        post('chatmaxima_wiz_key_add', { provider: m.provider, api_key: $.trim($('#cmw-key-input').val()) }).done(function (result) {
            m.credentialId = result.credential_id;
            $('#cmw-key-input').val('');
            unbusy($button);
            finish();
        }).fail(function (error) {
            unbusy($button);
            notice(error.message, 'error');
        });
    }

    // ---- 5. Install ----

    function renderInstall() {
        var name = botDisplayName();
        if (!ui.bot) {
            renderShell('<h2 class="cmw-h">' + esc(S.stepInstall) + '</h2><p>' + esc(S.botMissing) + '</p>' + footer('', '<button type="button" class="button button-primary" id="cmw-make-bot">' + esc(S.createChatbot) + '</button>'));
            $('#cmw-make-bot').on('click', function () { go('chatbot'); });
            return;
        }
        var live = C.installedChannel !== '' && C.installedChannel === (ui.channel ? ui.channel.account_id : C.channelAlias);
        var html = '<h2 class="cmw-h">' + esc(fmt(S.installTitle, name)) + '</h2><p class="cmw-intro">' + esc(S.installIntro) + '</p>';
        html += '<div class="cmw-cols"><div>';
        html += '<div class="cmw-live-card"><label class="cmw-switch"><input type="checkbox" id="cmw-live"' + (live ? ' checked' : '') + ' disabled><span class="cmw-switch-ui" aria-hidden="true"></span><span class="cmw-switch-text"><strong>' + esc(S.showOnSite) + '</strong></span></label>';
        html += '<p id="cmw-live-status"></p><p class="cmw-muted">' + esc(fmt(S.switchingOn, C.site.host)) + '</p></div>';
        html += '<h3 class="cmw-h3">' + esc(S.webChannel) + '</h3><div id="cmw-channel"><p class="cmw-muted"><span class="spinner is-active"></span> ' + esc(S.settingUpChannel) + '</p></div>';
        html += '<h3 class="cmw-h3">' + esc(S.aboutToFinish) + '</h3><table class="cmw-summary"><tbody>';
        html += '<tr><th>' + esc(S.account) + '</th><td>' + esc(C.user.email) + '</td></tr>';
        html += '<tr><th>' + esc(S.workspace) + '</th><td id="cmw-workspace-name">' + esc(C.user.teamName) + '</td></tr>';
        html += '<tr><th>' + esc(S.chatbot) + '</th><td>' + esc(name) + '</td></tr>';
        html += '<tr><th>' + esc(S.aiModel) + '</th><td>' + esc(C.state.model_label || 'MaxIA V4') + '</td></tr>';
        html += '</tbody></table></div>';
        html += '<div class="cmw-aside-box"><h3><span class="dashicons dashicons-visibility" aria-hidden="true"></span> ' + esc(S.previewWidget) + '</h3><p id="cmw-preview-note" class="cmw-muted">' + esc(S.settingUpChannel) + '</p><p class="cmw-muted">' + esc(S.changeLooks) + '</p></div></div>';
        var back = C.editing ? '<a class="button" href="' + esc(C.homeUrl) + '">' + esc(S.backHome) + '</a>' : '<button type="button" class="button" id="cmw-install-back">' + esc(S.back) + '</button>';
        var right = C.editing ? '<a class="button button-primary cmw-primary" href="' + esc(C.homeUrl) + '">' + esc(S.backHome) + '</a>' : '<span class="cmw-muted">' + esc(S.goLiveLater) + '</span> <button type="button" class="button button-primary cmw-primary" id="cmw-finish" disabled>' + esc(S.finishSetup) + '</button>';
        html += footer(back, right);
        renderShell(html);
        renderLiveStatus(live);
        if (!C.user.teamName) {
            loadTeams();
        }

        $('#cmw-install-back').on('click', function () {
            ui.chatbotTab = 'model';
            go('chatbot');
        });
        $('#cmw-live').on('change', function () { toggleLive($(this)); });
        $('#cmw-finish').on('click', function () {
            var $button = $(this);
            busy($button, S.finishing);
            post('chatmaxima_wiz_finish', { prompt_status: ui.bot.prompt_status || '' }).done(function (data) {
                window.location.href = data.homeUrl;
            }).fail(function (error) {
                unbusy($button);
                notice(error.message, 'error');
            });
        });
        attachChannel('');
    }

    function renderLiveStatus(live) {
        if (live) {
            $('#cmw-live-status').html('<span class="cmw-pill cmw-pill-ok">' + esc(S.live) + '</span> ' + esc(S.visitorsSee));
        } else {
            $('#cmw-live-status').html('<span class="cmw-pill">' + esc(S.notLive) + '</span> ' + esc(S.onlyAdmins));
        }
    }

    function attachChannel(accountId) {
        var data = {};
        if (accountId) {
            data.account_id = accountId;
        }
        if (ui.bot && ui.bot.avatar_url) {
            data.avatar_url = ui.bot.avatar_url;
        }
        post('chatmaxima_wiz_install_channel', data).done(function (result) {
            ui.channel = result.channel;
            C.channelAlias = ui.channel.account_id;
            if (C.installedChannel) {
                C.installedChannel = ui.channel.account_id; // the server moved the live widget along
            }
            $('#cmw-channel').html('<p>' + esc(fmt(S.channelUsing, ui.channel.account_name)) + '</p><p><a href="#" id="cmw-other-channel">' + esc(S.useOtherChannel) + '</a></p>');
            $('#cmw-other-channel').on('click', function (event) {
                event.preventDefault();
                showChannelPicker('');
            });
            $('#cmw-live').prop('disabled', false);
            $('#cmw-finish').prop('disabled', false);
            loadWidgetPreview(ui.channel.account_id);
        }).fail(function (error) {
            if (error.code === 'CHANNEL_LIMIT_REACHED') {
                showChannelPicker(S.channelLimit);
                return;
            }
            $('#cmw-channel').html('<p class="cmw-inline-error">' + esc(error.message) + '</p>');
        });
    }

    function showChannelPicker(message) {
        $('#cmw-channel').html('<p class="cmw-muted"><span class="spinner is-active"></span></p>');
        post('chatmaxima_get_channels', { platform: 'livechatwidget' }).done(function (data) {
            var channels = $.grep(data.channels || [], function (channel) { return channel.account_platform === 'livechatwidget'; });
            var html = '';
            if (message) {
                html += '<p class="cmw-inline-warning">' + esc(message) + '</p>';
            }
            html += '<div class="cmw-cards">';
            $.each(channels, function (i, channel) {
                var answeredBy = S.noBot;
                if (channel.attached_bot) {
                    answeredBy = fmt(S.answeredBy, channel.attached_bot.bot_name);
                }
                html += '<div class="cmw-choice"><span class="cmw-choice-body"><span class="cmw-choice-title">' + esc(channel.account_name) + '</span><span class="cmw-choice-meta">' + esc(channel.site_url || '') + ' · ' + esc(answeredBy) + '</span></span><button type="button" class="button" data-channel="' + esc(channel.account_id) + '">' + esc(S.useThisChannel) + '</button></div>';
            });
            html += '</div>';
            $('#cmw-channel').html(html);
            $('#cmw-channel').find('[data-channel]').on('click', function () {
                $('#cmw-channel').html('<p class="cmw-muted"><span class="spinner is-active"></span> ' + esc(S.settingUpChannel) + '</p>');
                attachChannel($(this).data('channel'));
            });
        }).fail(function (error) {
            $('#cmw-channel').html('<p class="cmw-inline-error">' + esc(error.message) + '</p>');
        });
    }

    function toggleLive($input) {
        var on = $input.is(':checked');
        $input.prop('disabled', true);
        var request;
        if (on) {
            request = post('chatmaxima_install_widget', { alias: C.channelAlias });
        } else {
            request = post('chatmaxima_uninstall_widget');
        }
        request.done(function () {
            C.installedChannel = on ? C.channelAlias : '';
            renderLiveStatus(on);
        }).fail(function (error) {
            $input.prop('checked', !on);
            notice(error.message, 'error');
        }).always(function () {
            $input.prop('disabled', false);
        });
    }

    // The real widget in the admin, for this user only
    function loadWidgetPreview(alias) {
        if (!alias) {
            return;
        }
        $('#cmw-preview-note').text(S.previewLoaded);
        if (ui.widgetLoaded) {
            return;
        }
        ui.widgetLoaded = true;
        window.chatmaximaConfig = { token: alias };
        var script = document.createElement('script');
        script.src = 'https://widget.chatmaxima.com/embed.min.js';
        script.id = alias;
        script.defer = true;
        document.body.appendChild(script);
    }

    // ---- Home ----

    function renderHome() {
        var live = C.installedChannel !== '';
        var name = C.state.bot_name || S.chatbot;
        $('#cmw-subtitle').html('<span class="cmw-status-dot' + (live ? ' is-ok' : '') + '"></span>' + esc(live ? fmt(S.homeWorking, name, C.site.host) : fmt(S.homeNotLive, name)));
        $('#cmw-header-actions').html('<a class="button" href="' + esc(C.site.url) + '" target="_blank" rel="noopener noreferrer">' + esc(S.viewSite) + '</a> <a class="button button-primary" href="' + esc(safeUrl(C.dashboardUrl)) + '" target="_blank" rel="noopener noreferrer">' + esc(S.openDashboard) + ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>');

        var edit = function (step) { return C.homeUrl + '&edit=' + step; };
        var html = '<div id="cmw-home-banner"></div>';
        if (C.site.isLocal) {
            html += '<div class="cmw-inline-warning">' + esc(fmt(S.localSite, C.site.host)) + '</div>';
        }
        html += '<div class="cmw-home-grid">';

        // Connection
        html += card('admin-links', S.connection, '<span class="cmw-pill cmw-pill-ok">' + esc(S.connected) + '</span>',
            row(S.account, '<strong>' + esc(C.user.name) + '</strong> · ' + esc(C.user.email)) +
            row(S.workspace, '<span id="cmw-workspace-name">' + esc(C.user.teamName) + '</span>') +
            (C.state.completed_at ? row(S.connectedOn, esc(String(C.state.completed_at).split(' ')[0])) : ''),
            '<button type="button" class="button" id="cmw-other-account">' + esc(S.differentAccount) + '</button><button type="button" class="cmw-link-btn cmw-danger" id="cmw-disconnect">' + esc(S.disconnect) + '</button>');

        // Knowledge and sync
        var typesLabel = [];
        $.each(C.postTypes || [], function (i, type) {
            if (selectedTypes().indexOf(type.name) !== -1) {
                typesLabel.push(esc(type.label) + ' ' + esc(type.count));
            }
        });
        var synced = C.lastFullSync && C.lastFullSync.total;
        var last = (C.recentSync || [])[0];
        var limited = !!(C.planLimit && C.planLimit.reached);
        var pill = '<span class="cmw-pill' + (synced ? ' cmw-pill-ok' : '') + '" id="cmw-home-ks-pill">' + esc(synced ? S.upToDate : S.notSynced) + '</span>';
        if (limited) {
            pill = '<span class="cmw-pill cmw-pill-warn" id="cmw-home-ks-pill">' + esc(S.planLimitPill) + '</span>';
        }
        html += card('book', S.knowledgeSync, pill,
            '<div id="cmw-home-limit">' + (limited ? planLimitHtml(C.planLimit.limit, C.planLimit.upgrade_url) : '') + '</div>' +
            row(S.source, '<span id="cmw-home-ks">' + esc(C.knowledgeAlias) + '</span>') +
            row(S.readStatus, '<span id="cmw-home-read"><span class="spinner is-active"></span></span>') +
            row(S.syncing, typesLabel.join(' · ')) +
            row(S.autoSync, '<label class="cmw-switch cmw-switch-inline"><input type="checkbox" id="cmw-home-auto"' + (C.autoSync ? ' checked' : '') + '><span class="cmw-switch-ui" aria-hidden="true"></span><span>' + esc(C.autoSync ? S.on : S.off) + '</span></label>') +
            (last ? row(S.lastChange, esc(last.date) + ': ' + esc(last.title) + ' (' + esc(last.type) + ')') : ''),
            '<a class="button" href="' + esc(edit('knowledge')) + '"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' + esc(S.syncNow) + '</a><a class="button" href="' + esc(edit('knowledge')) + '">' + esc(S.editContentTypes) + '</a>');

        // Chatbot
        html += card('format-chat', S.chatbotModel, '<span class="cmw-pill" id="cmw-home-bot-pill">…</span>',
            '<div id="cmw-home-bot"><span class="spinner is-active"></span></div>',
            '<a class="button" href="' + esc(edit('chatbot')) + '">' + esc(S.editPersona) + '</a><a class="button" href="' + esc(edit('model')) + '">' + esc(S.changeModel) + '</a>');

        // Widget
        html += card('desktop', S.websiteWidget, '<span class="cmw-pill' + (live ? ' cmw-pill-ok' : '') + '" id="cmw-home-live-pill">' + esc(live ? S.live : S.notLive) + '</span>',
            '<div id="cmw-home-channel-note"></div>' +
            row(S.showOnSite, '<label class="cmw-switch cmw-switch-inline"><input type="checkbox" id="cmw-home-live"' + (live ? ' checked' : '') + (C.channelAlias || C.installedChannel ? '' : ' disabled') + '><span class="cmw-switch-ui" aria-hidden="true"></span><span id="cmw-home-live-text">' + esc(live ? S.onForAll : S.offForAll) + '</span></label>') +
            row(S.webChannel, esc(C.installedChannel || C.channelAlias)) +
            row(S.looks, '<a href="' + esc(safeUrl(C.dashboardUrl)) + '" target="_blank" rel="noopener noreferrer">' + esc(S.changeInChatMaxima) + '</a>'),
            '<button type="button" class="button" id="cmw-home-preview">' + esc(S.previewWidget) + '</button><a class="button" href="' + esc(edit('install')) + '">' + esc(S.changeChannel) + '</a>');

        html += '</div>';

        // Recent activity
        html += '<div class="cmw-card cmw-card-full"><div class="cmw-card-head"><h2 class="cmw-card-title">' + esc(S.recentActivity) + '</h2></div>';
        if (C.recentSync && C.recentSync.length) {
            html += '<table class="widefat striped cmw-activity"><thead><tr><th>' + esc(S.when) + '</th><th>' + esc(S.item) + '</th><th>' + esc(S.type) + '</th><th>' + esc(S.result) + '</th></tr></thead><tbody>';
            $.each(C.recentSync, function (i, item) {
                var ok = item.status === 'synced';
                html += '<tr><td>' + esc(item.date) + '</td><td>' + esc(item.title) + '</td><td>' + esc(item.type) + '</td><td class="' + (ok ? 'cmw-ok' : 'cmw-err') + '">' + esc(ok ? S.synced : S.failed) + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p class="cmw-card-body cmw-muted">' + esc(S.noActivity) + '</p>';
        }
        html += '</div><p class="cmw-muted">' + esc(S.startFresh) + ' <button type="button" class="cmw-link-btn cmw-danger" id="cmw-reset">' + esc(S.resetPlugin) + '</button></p>';
        $app.html(html);

        loadHomeBot();
        loadHomeChannel();
        loadHomeKnowledgeName();
        loadReadStatus($('#cmw-home-read'));
        if (!C.user.teamName) {
            loadTeams();
        }

        $('#cmw-home-auto').on('change', function () {
            C.autoSync = $(this).is(':checked');
            $(this).siblings('span').last().text(C.autoSync ? S.on : S.off);
            saveSyncSettings().fail(function (error) { notice(error.message, 'error'); });
        });
        $('#cmw-home-live').on('change', function () {
            var $input = $(this);
            var on = $input.is(':checked');
            if (on && !C.channelAlias) {
                C.channelAlias = C.installedChannel;
            }
            $input.prop('disabled', true);
            var request = on ? post('chatmaxima_install_widget', { alias: C.channelAlias }) : post('chatmaxima_uninstall_widget');
            request.done(function () {
                C.installedChannel = on ? C.channelAlias : '';
                $('#cmw-home-live-text').text(on ? S.onForAll : S.offForAll);
                $('#cmw-home-live-pill').text(on ? S.live : S.notLive).toggleClass('cmw-pill-ok', on);
                notice(S.saved, 'success');
            }).fail(function (error) {
                $input.prop('checked', !on);
                notice(error.message, 'error');
            }).always(function () { $input.prop('disabled', false); });
        });
        $('#cmw-home-preview').on('click', function () {
            loadWidgetPreview(C.installedChannel || C.channelAlias);
            notice(S.previewLoaded, 'info');
        });
        $('#cmw-other-account, #cmw-disconnect').on('click', function () {
            confirmBox(S.disconnectConfirm, function () {
                post('chatmaxima_logout').always(function () { window.location.href = C.homeUrl; });
            });
        });
        $('#cmw-reset').on('click', function () {
            confirmBox(S.resetConfirm, function () {
                post('chatmaxima_wiz_reset').always(function () { window.location.href = C.homeUrl; });
            });
        });
    }

    function card(icon, title, badge, body, actions) {
        return '<div class="cmw-card"><div class="cmw-card-head"><span class="cmw-card-icon dashicons dashicons-' + icon + '" aria-hidden="true"></span><h2 class="cmw-card-title">' + esc(title) + '</h2>' + badge + '</div><div class="cmw-card-body"><table class="cmw-rows"><tbody>' + body + '</tbody></table></div><div class="cmw-card-actions">' + actions + '</div></div>';
    }

    function row(label, valueHtml) {
        return '<tr><th scope="row">' + esc(label) + '</th><td>' + valueHtml + '</td></tr>';
    }

    function loadHomeBot() {
        post('chatmaxima_wiz_get_bot').done(function (data) {
            var bot = data.bot;
            if (!bot) {
                $('#cmw-home-bot').html('<p>' + esc(S.botMissing) + '</p><a class="button button-primary" href="' + esc(C.homeUrl + '&edit=chatbot') + '">' + esc(S.createChatbot) + '</a>');
                $('#cmw-home-bot-pill').text(S.notLive);
                return;
            }
            ui.bot = bot;
            var roleLabel = S.roleSupport;
            if (bot.purpose === 'sell_products') {
                roleLabel = S.roleSales;
            } else if (bot.purpose === 'book_appointments') {
                roleLabel = S.roleBooking;
            } else if (bot.purpose === 'custom') {
                roleLabel = S.roleCustom;
            }
            var toneKey = 'tone' + String(bot.tone || 'friendly').charAt(0).toUpperCase() + String(bot.tone || 'friendly').slice(1);
            var modelLabel = C.state.model_label || 'MaxIA V4';
            var modelNote = (bot.model && bot.model.provider && bot.model.provider !== 'chatmaxima') ? S.ownKey : S.free;
            var welcome = String(bot.welcome_message || '');
            if (welcome.length > 70) {
                welcome = welcome.substring(0, 70) + '...';
            }
            $('#cmw-home-bot').html('<table class="cmw-rows"><tbody>' +
                row(S.name, '<strong>' + esc(bot.name) + '</strong> · ' + esc(roleLabel) + ' · ' + esc(S[toneKey] || bot.tone || '')) +
                row(S.aiModel, esc(modelLabel) + ' <span class="cmw-muted">(' + esc(modelNote) + ')</span>') +
                row(S.welcome, '"' + esc(welcome) + '"') + '</tbody></table>');
            if (bot.status === 'inactive') {
                // Deactivated in ChatMaxima: it answers on no channel, this site included
                $('#cmw-home-bot').prepend(noteHtml(S.botInactive, bot.builder_url, S.openInChatMaxima, true));
                $('#cmw-home-bot-pill').text(S.ksInactivePill).addClass('cmw-pill-warn');
                return;
            }
            $('#cmw-home-bot-pill').text(S.answering).addClass('cmw-pill-ok');
            if (bot.prompt_status === 'default') {
                // Which message depends on whether ChatMaxima has read any page yet, and why not
                post('chatmaxima_wiz_training_status').always(function (status) {
                    var unreadable = status && status.total && !status.indexed && status.reason;
                    var text = S.promptPending;
                    if (unreadable) {
                        text = fmt(S.promptUnreadable, status.reason);
                    }
                    var html = '<div class="cmw-banner">' + esc(text);
                    if (!unreadable) {
                        html += ' <button type="button" class="button" id="cmw-rewrite">' + esc(S.rewritePrompt) + '</button>';
                    }
                    html += '</div>';
                    $('#cmw-home-banner').html(html);
                    $('#cmw-rewrite').on('click', function () {
                        var $button = $(this);
                        busy($button, S.saving);
                        post('chatmaxima_wiz_save_bot', { regenerate_prompt: '1' }).done(function (result) {
                            if (result.bot && result.bot.prompt_status === 'generated') {
                                $('#cmw-home-banner').empty();
                                notice(S.promptRewritten, 'success');
                                return;
                            }
                            unbusy($button);
                            notice(S.stillIndexing, 'warning');
                        }).fail(function (error) {
                            unbusy($button);
                            notice(error.message, 'error');
                        });
                    });
                });
            }
        }).fail(function (error) {
            $('#cmw-home-bot').html('<p class="cmw-inline-error">' + esc(error.message) + '</p>');
        });
    }

    // ChatMaxima reads synced pages in the background; a page it could not fetch stays unread, with a reason
    function loadReadStatus($target) {
        if (!C.knowledgeAlias || !$target.length) {
            return;
        }
        post('chatmaxima_wiz_training_status').done(function (data) {
            var total = data.total || 0;
            var indexed = data.indexed || 0;
            var html;
            if (data.inactive) {
                $target.text(S.ksInactiveRead); // loadHomeKnowledgeName shows the notice and the way out
                return;
            }
            C.planLimit = data.plan_limit || null;
            if ($('#cmw-home-limit').length) {
                if (C.planLimit && C.planLimit.reached) {
                    $('#cmw-home-limit').html(planLimitHtml(C.planLimit.limit, C.planLimit.upgrade_url));
                    $('#cmw-home-ks-pill').attr('class', 'cmw-pill cmw-pill-warn').text(S.planLimitPill);
                } else {
                    $('#cmw-home-limit').empty(); // upgraded, or under the cap
                }
            }
            if (!total) {
                html = esc(S.readPending);
            } else if (!indexed) {
                html = '<span class="cmw-err">' + esc(S.readNone) + '</span>';
                if (data.reason) {
                    html += ' <span class="cmw-muted">' + esc(data.reason) + '</span>';
                } else {
                    html += ' <span class="cmw-muted">' + esc(S.readPending) + '</span>';
                }
            } else {
                html = esc(fmt(S.readByChatMaxima, indexed, total));
                if (indexed < total && data.reason) {
                    html += ' <span class="cmw-muted">' + esc(data.reason) + '</span>';
                }
            }
            html += ' <button type="button" class="cmw-link-btn cmw-read-refresh">' + esc(S.refresh) + '</button>';
            $target.html(html);
            $target.find('.cmw-read-refresh').on('click', function () {
                $target.html('<span class="spinner is-active"></span>');
                loadReadStatus($target);
            });
        }).fail(function (error) {
            $target.text(error.message);
        });
    }

    // A web channel switched off in ChatMaxima hides the widget on the site; say so instead of Live
    function loadHomeChannel() {
        if (!C.installedChannel && !C.channelAlias) {
            return;
        }
        post('chatmaxima_wiz_channel_status').done(function (data) {
            if (data.active) {
                return;
            }
            $('#cmw-home-channel-note').html(noteHtml(S.channelOff, C.homeUrl + '&edit=install', S.changeChannel, false));
            $('#cmw-home-live-pill').attr('class', 'cmw-pill cmw-pill-warn').text(S.channelOffPill);
            if (C.installedChannel) {
                $('#cmw-subtitle').html('<span class="cmw-status-dot"></span>' + esc(fmt(S.homeChannelOff, C.state.bot_name || S.chatbot)));
            }
        });
    }

    function loadHomeKnowledgeName() {
        if (!C.knowledgeAlias) {
            return;
        }
        post('chatmaxima_get_knowledge_sources').done(function (data) {
            if (data.selected_inactive) {
                $('#cmw-home-ks-pill').attr('class', 'cmw-pill cmw-pill-warn').text(S.ksInactivePill);
                $('#cmw-home-limit').html(noteHtml(S.ksInactive, C.homeUrl + '&edit=knowledge', S.chooseKs, false));
                return;
            }
            $.each(data.knowledge_sources || [], function (i, source) {
                if (source.alias === C.knowledgeAlias) {
                    $('#cmw-home-ks').html('<strong>' + esc(source.name) + '</strong> · ' + esc(fmt(S.nDocuments, source.document_count || 0)));
                }
            });
        });
    }

    // ---- start ----

    function start() {
        if (C.view === 'home') {
            renderHome();
            return;
        }
        if (!C.isAuthenticated) {
            go('connect');
            return;
        }
        loadTeams().always(function () {
            ui.workspaceNeeded = (ui.teams || []).length > 1;
            var step = ui.step;
            if (step === 'connect') {
                step = 'knowledge';
            }
            if (ui.workspaceNeeded && !C.state.workspace_confirmed && step === 'knowledge' && !C.editing) {
                step = 'workspace';
            }
            if (step === 'workspace' && !ui.workspaceNeeded) {
                step = 'knowledge';
            }
            go(step);
        });
    }

    $(start);
})(jQuery);
