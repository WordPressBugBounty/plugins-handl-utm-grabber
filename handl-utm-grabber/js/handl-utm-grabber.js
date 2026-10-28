var qvars = getUrlVars()
var handlConsentGranted = typeof handl_utm_cookie_duration === 'undefined' || handl_utm_cookie_duration[1] === '1'

jQuery(function($) {
    if (handlConsentGranted) {
        RunHandL()
    }
});

function RunHandL(){
    handlConsentGranted = true
    setHandLParams()
    RunFieldFiller()
    HandLAppendTrackedLinks()
    HandLBindNinjaFormsFiller()
}

// NF inputs (#nf-field-{id}) carry no param name, so the generic selector
// pass can't reach them; re-fill on nfFormReady ('' when the cookie is absent).
var handlNfFillerBound = false
function HandLBindNinjaFormsFiller(){
    if (handlNfFillerBound) return
    handlNfFillerBound = true

    jQuery(document).on( 'nfFormReady', function () {
        if (typeof nfForms === 'undefined') return
        nfForms.forEach(function (form) {
            form.fields.map(function (item) {
                if (item.default) {
                    var matches = String(item.default).match(/^{(\w+):(\w+)/)
                    if (matches && matches.length == 3 && matches[1] === 'handl') {
                        var c = Cookies.get(matches[2])
                        jQuery('#nf-field-' + item.id).val(c === undefined ? '' : handlSafeDecode(decodeURIComponent, c).replace(/[%]/g,' '))
                    }
                }
            })
        })
    })
}

// IP stays server-side.
function setHandLParams(){
    var cookieOptions = { expires: parseInt(handl_utm_cookie_duration[0]) }

    Cookies.set('handl_url', document.location.href, cookieOptions)
    Cookies.set('handl_ref', document.referrer, cookieOptions)

    if (Cookies.get('handl_landing_page') === undefined) {
        Cookies.set('handl_landing_page', document.location.href, cookieOptions)
    }

    if (Cookies.get('handl_original_ref') === undefined) {
        Cookies.set('handl_original_ref', document.referrer, cookieOptions)
    }
}

function HandLAppendTrackedLinks(){
    jQuery('a.utm-out, .utm-out a').not('#wpadminbar a, .no-utm, .no-utm a').each(function(){
        if (!/^https?:/.test(this.href) || this.getAttribute('href').charAt(0) === '#') {
            return
        }
        var url = new URL(this.href)
        for (var key in handl_utm) {
            if (handl_utm[key] && !url.searchParams.has(key)) {
                url.search += (url.search ? '&' : '?') + key + '=' + encodeURIComponent(handl_utm[key])
            }
        }
        this.href = url.href
    })
}

// Marketing consent granted -> capture; withdrawn -> clean up. No reload.
function HandLOnMarketingConsentChange(changedConsentCategory) {
    for (var key in changedConsentCategory) {
        if (changedConsentCategory.hasOwnProperty(key)) {
            if (key === 'marketing' && changedConsentCategory[key] === 'allow') {
                console.log('Consent: Marketing consent granted, running HandL');
                RunHandL();
            } else if (key === 'marketing' && changedConsentCategory[key] === 'deny') {
                console.log('Consent: Marketing consent withdrawn, removing HandL cookies');
                RemoveHandLCookies();
            }
        }
    }
}

// WP Consent API broadcasts (any consent manager, incl. our banner in manager mode).
document.addEventListener('wp_listen_for_consent_change', function (e) {
    HandLOnMarketingConsentChange(e.detail);
});

// Our banner's own event, standalone mode.
document.addEventListener('handl_consent_change', function (e) {
    HandLOnMarketingConsentChange(e.detail);
});

// Server-side cookies carry a leading-dot domain, JS-set ones none; clear every variant.
function RemoveHandLCookies() {
    console.log('Removing HandL cookies');
    var params = typeof handl_utm_all_params !== 'undefined' ? Object.values(handl_utm_all_params) : []
    var host = location.hostname.toLowerCase().replace(/^www\./, '')
    params.forEach(function (param) {
        Cookies.remove(param, { path: '/' })
        Cookies.remove(param, { path: '/', domain: '.' + host })
        Cookies.remove(param, { path: '/', domain: host })
    })
    handlConsentGranted = false
}

function RunFieldFiller(){
    jQuery.each([ 'utm_source','utm_medium','utm_term', 'utm_content', 'utm_campaign', 'gclid', 'handl_landing_page', 'handl_original_ref', 'handl_ip', 'handl_ref', 'handl_url', 'email', 'username' ], function( i,v ) {

        var cookie_field = GetQVars(v,qvars)

        if ( cookie_field != '' )
            Cookies.set(v, cookie_field, { expires: 30 });

        var curval = Cookies.get(v)

        if (curval != undefined) {
            curval = handlSafeDecode(decodeURIComponent, curval)

            if ([ 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign', 'gclid' ].indexOf(v) !== -1) {
                handl_utm[v] = curval
            }

            curval = curval.replace(/[%]/g,' ')

            if (v == 'username') {
                //Maybe this should apply to all... We'll see...
                curval = curval.replace(/\+/g, ' ')
            }
        } else {
            // No cookie: blank tracked fields (cached pages carry baked values).
            // skip email/username
            if ([ 'email', 'username' ].indexOf(v) !== -1) {
                return
            }
            curval = ''
        }

        {
            jQuery('input[name=\"'+v+'\"]').val(curval)
            jQuery('input#'+v).val(curval)
            jQuery('input.'+v).val(curval)
            jQuery('input#form-field-'+v).val(curval)

            //for nested input fix
            jQuery('#'+v).find('input').val(curval)
            jQuery('.'+v).find('input').val(curval)

            jQuery("[data-original_id='"+v+"']").val(curval)
            jQuery("[data-name='"+v+"']").val(curval)
            jQuery("[data-name='"+v.replace(/_/g,'')+"']").val(curval) //Active Campaign
            jQuery("[placeholder='"+v+"']").val(curval) //Dyanmics 365 + Generic

            if (v.length > 4 && ['email','username'].indexOf(v) == -1){ // this is for making sure wildcards are not hyper sensitive. (email/username are free-only params, V3 never wildcards them)
                //wildcard selector
                jQuery("[name*="+v+"]").val(curval)
                jQuery("[id*="+v+"]").val(curval)
                jQuery("[class*="+v+"]").val(curval)
            }

        }
    });
}

// A value that cannot be decoded, like "20% off", is kept as it is instead of stopping the script.
function handlSafeDecode(decode, value) {
    try {
        return decode(value);
    } catch (e) {
        return value;
    }
}

function GetQVars(v,qvars){
    if (qvars[v] != undefined) {
        return qvars[v]
    }
    return ''
}

function getUrlVars() {
    var vars = {};
    var parts = window.location.href.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
        vars[key] = value;
    });
    return vars;
}

jQuery( document ).on( 'elementor/popup/show' , function () {
    setTimeout(function(){
        if (handlConsentGranted) {
            RunFieldFiller()
        }
    }, 1000)
} );
