{% verbatim %}

/* gettext-compatible _ function */
function _(s) {
    return (typeof l10n !== 'undefined' && typeof l10n[s] !== 'undefined') ? l10n[s] : s;
}

/* printf-like formatting function */
function fmt(s, a) {
    return s.replace(/\{([0-9]+)\}/g, function(x) { return a[x[1]]; });
}

const saved = {};

let selectedstyle = '{% endverbatim %}{{ config.default_stylesheet.0|addslashes }}{% verbatim %}';

const styles = {
    {% endverbatim %}
    {% for stylesheet in stylesheets %}{% verbatim %}
    '{% endverbatim %}{{ stylesheet.name|addslashes }}{% verbatim %}': '{% endverbatim %}{{ stylesheet.uri|addslashes }}{% verbatim %}',
    {% endverbatim %}{% endfor %}{% verbatim %}
};

let board_name = false;

function changeStyle(styleName, link) {
    {% endverbatim %}
    {% if config.stylesheets_board %}{% verbatim %}
    if (board_name) {
        stylesheet_choices[board_name] = styleName;
        localStorage.board_stylesheets = JSON.stringify(stylesheet_choices);
    }
    {% endverbatim %}{% else %}
    localStorage.stylesheet = styleName;
    {% endif %}
    {% verbatim %}

    if (!document.getElementById('stylesheet')) {
        const s = document.createElement('link');
        s.rel = 'stylesheet';
        s.type = 'text/css';
        s.id = 'stylesheet';
        const x = document.getElementsByTagName('head')[0];
        x.appendChild(s);
    }

    document.getElementById('stylesheet').href = styles[styleName];
    selectedstyle = styleName;

    if (document.getElementsByClassName('styles').length !== 0) {
        const styleLinks = document.getElementsByClassName('styles')[0].childNodes;
        for (let i = 0; i < styleLinks.length; i++) {
            styleLinks[i].className = '';
        }
    }

    if (link) {
        link.className = 'selected';
    }

    if (typeof $ !== 'undefined') {
        $(window).trigger('stylesheet', styleName);
    }
}

{% endverbatim %}
{% if config.stylesheets_board %}
    {# This is such an unacceptable mess. There needs to be an easier way. #}
    var matches = document.URL.match(/\/(\w+)\/($|{{ config.dir.res|replace({'/': '\\/'}) }}{{ config.file_page|replace({'%d': '\\d+', '.': '\\.'}) }}|{{ config.file_index|replace({'.': '\\.'}) }}|{{ config.file_page|replace({'%d': '\\d+', '.': '\\.'}) }})/);
    {% verbatim %}
    if (matches) {
        board_name = matches[1];
    }

    if (!localStorage.board_stylesheets) {
        localStorage.board_stylesheets = '{}';
    }

    var stylesheet_choices = JSON.parse(localStorage.board_stylesheets);
    if (board_name && stylesheet_choices[board_name]) {
        for (var styleName in styles) {
            if (styleName === stylesheet_choices[board_name]) {
                changeStyle(styleName);
                break;
            }
        }
    }
    {% endverbatim %}
{% else %}
    {% verbatim %}
    if (localStorage.stylesheet) {
        for (var styleName in styles) {
            if (styleName === localStorage.stylesheet) {
                changeStyle(styleName);
                break;
            }
        }
    }
    {% endverbatim %}
{% endif %}
{% verbatim %}

function init_stylechooser() {
    const newElement = document.createElement('div');
    newElement.className = 'styles';

    for (let styleName in styles) {
        const style = document.createElement('a');
        style.innerHTML = '[' + styleName + ']';
        style.onclick = function() {
            changeStyle(this.innerHTML.substring(1, this.innerHTML.length - 1), this);
        };
        if (styleName === selectedstyle) {
            style.className = 'selected';
        }
        style.href = 'javascript:void(0);';
        newElement.appendChild(style);
    }

    document.getElementsByTagName('body')[0].insertBefore(
        newElement,
        document.getElementsByTagName('body')[0].lastChild.nextSibling
    );
}

function get_cookie(cookie_name) {
    const results = document.cookie.match('(^|;) ?' + cookie_name + '=([^;]*)(;|$)');
    if (results)
        return unescape(results[2]);
    else
        return null;
}

function highlightReply(id) {
    if (typeof window.event !== "undefined" && event.which === 2) {
        // don't highlight on middle click
        return true;
    }

    const divs = document.getElementsByTagName('div');
    for (let i = 0; i < divs.length; i++) {
        if (divs[i].className.indexOf('post') !== -1)
            divs[i].className = divs[i].className.replace(/highlighted/, '');
    }
    if (id) {
        const post = document.getElementById('reply_' + id);
        if (post)
            post.className += ' highlighted';
    }
    return false;
}

function generatePassword() {
    let pass = '';
    const chars = '{% endverbatim %}{{ config.genpassword_chars }}{% verbatim %}';
    for (let i = 0; i < 8; i++) {
        const rnd = Math.floor(Math.random() * chars.length);
        pass += chars.substring(rnd, rnd + 1);
    }
    return pass;
}

function dopost(form) {
    if (form.elements['name']) {
        localStorage.name = form.elements['name'].value.replace(/( |^)## .+$/, '');
    }
    if (form.elements['password']) {
        localStorage.password = form.elements['password'].value;
    }
    if (form.elements['email'] && form.elements['email'].value !== 'sage') {
        localStorage.email = form.elements['email'].value;
    }

    saved[document.location] = form.elements['body'].value;
    sessionStorage.body = JSON.stringify(saved);

    return form.elements['body'].value !== "" ||
        form.elements['file'].value !== "" ||
        (form.elements.file_url && form.elements['file_url'].value !== "");
}

function citeReply(id, with_link) {
    const textarea = document.getElementById('body');

    if (document.selection) {
        // IE
        textarea.focus();
        const sel = document.selection.createRange();
        sel.text = '>>' + id + '\n';
    } else if (textarea.selectionStart || textarea.selectionStart === '0') {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        textarea.value = textarea.value.substring(0, start) +
            '>>' + id + '\n' +
            textarea.value.substring(end, textarea.value.length);

        textarea.selectionStart += ('>>' + id).length + 1;
        textarea.selectionEnd = textarea.selectionStart;
    } else {
        // ??? (fallback)
        textarea.value += '>>' + id + '\n';
    }
    if (typeof $ !== 'undefined') {
        $(window).trigger('cite', [id, with_link]);
        $(textarea).change();
    }
    return false;
}

function rememberStuff() {
    if (document.forms.post) {
        if (document.forms.post.password) {
            if (!localStorage.password)
                localStorage.password = generatePassword();
            document.forms.post.password.value = localStorage.password;
        }

        if (localStorage.name && document.forms.post.elements['name'])
            document.forms.post.elements['name'].value = localStorage.name;
        if (localStorage.email && document.forms.post.elements['email'])
            document.forms.post.elements['email'].value = localStorage.email;

        if (window.location.hash.indexOf('q') === 1)
            citeReply(window.location.hash.substring(2), true);

        if (sessionStorage.body) {
            const saved = JSON.parse(sessionStorage.body);
            if (get_cookie('{% endverbatim %}{{ config.cookies.js }}{% verbatim %}')) {
                // Remove successful posts
                const successful = JSON.parse(get_cookie('{% endverbatim %}{{ config.cookies.js }}{% verbatim %}'));
                for (let url in successful) {
                    saved[url] = null;
                }
                sessionStorage.body = JSON.stringify(saved);

                document.cookie = '{% endverbatim %}{{ config.cookies.js }}{% verbatim %}={};expires=0;path=/;';
            }
            if (saved[document.location]) {
                document.forms.post.body.value = saved[document.location];
            }
        }

        if (localStorage.body) {
            document.forms.post.body.value = localStorage.body;
            localStorage.body = '';
        }
    }
}

var script_settings = function(script_name) {
    this.script_name = script_name;
    this.get = function(var_name, default_val) {
        if (typeof tb_settings === 'undefined' ||
            typeof tb_settings[this.script_name] === 'undefined' ||
            typeof tb_settings[this.script_name][var_name] === 'undefined')
            return default_val;
        return tb_settings[this.script_name][var_name];
    };
};

function init() {
    init_stylechooser();

    if (document.forms.postcontrols) {
        document.forms.postcontrols.password.value = localStorage.password;
    }

    if (window.location.hash.indexOf('q') !== 1 && window.location.hash.substring(1))
        highlightReply(window.location.hash.substring(1));
}

const RecaptchaOptions = {
    theme: 'clean'
};

let onready_callbacks = [];

function onready(fnc) {
    onready_callbacks.push(fnc);
}

function ready() {
    for (let i = 0; i < onready_callbacks.length; i++) {
        onready_callbacks[i]();
    }
}

onready(init);

{% endverbatim %}{% if config.google_analytics %}
{% verbatim %}

var _gaq = _gaq || [];
_gaq.push(['_setAccount', '{% endverbatim %}{{ config.google_analytics }}{% verbatim %}']);
{% endverbatim %}{% if config.google_analytics_domain %}
{% verbatim %}_gaq.push(['_setDomainName', '{% endverbatim %}{{ config.google_analytics_domain }}{% verbatim %}']){% endverbatim %}{% endif %}{% if not config.google_analytics_domain %}
{% verbatim %}_gaq.push(['_setDomainName', 'none']){% endverbatim %}{% endif %}{% verbatim %};_gaq.push(['_trackPageview']);(function() {var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;ga.src = ('https:' == document.location.protocol ? 'https://' : 'http://') + 'stats.g.doubleclick.net/dc.js';var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);})();{% endverbatim %}{% endif %}
