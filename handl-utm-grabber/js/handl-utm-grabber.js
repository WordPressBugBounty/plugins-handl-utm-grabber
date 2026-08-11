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
    jQuery('.utm-out').each(function(){
        // Only process if this is an anchor tag with href
        if (this.tagName.toLowerCase() !== 'a' || !this.href) {
            return;
        }

        // Sanitize URL parameters and handl_utm object
        var urlParams = getSearchParams(this.href);
        var sanitizedParams = {};

        // Only include parameters that exist in handl_utm
        for(var key in urlParams) {
            if(handl_utm.hasOwnProperty(key)) {
                sanitizedParams[key] = encodeURIComponent(urlParams[key]);
            }
        }

        // Sanitize handl_utm values
        var sanitizedHandlUtm = {};
        for(var key in handl_utm) {
            sanitizedHandlUtm[key] = encodeURIComponent(handl_utm[key]);
        }

        // Merge sanitized objects
        var merged = jQuery.extend({}, sanitizedHandlUtm, sanitizedParams);

        // Reset href and append sanitized parameters
        this.href = this.href.split('?')[0]; // Keep base URL only
        if(!jQuery.isEmptyObject(merged)) {
            this.href += "?" + jQuery.param(merged);
        }
    });
}

// WP Consent API
document.addEventListener('wp_listen_for_consent_change', function (e) {
    var changedConsentCategory = e.detail;
    for (var key in changedConsentCategory) {
        if (changedConsentCategory.hasOwnProperty(key)) {
            if (key === 'marketing' && changedConsentCategory[key] === 'allow') {
                console.log('WP Consent API: Marketing consent granted, running HandL');
                RunHandL();
            }
        }
    }
});

function RunFieldFiller(){
    jQuery.each([ 'utm_source','utm_medium','utm_term', 'utm_content', 'utm_campaign', 'gclid', 'handl_landing_page', 'handl_original_ref', 'handl_ip', 'handl_ref', 'handl_url', 'email', 'username' ], function( i,v ) {

        var cookie_field = GetQVars(v,qvars)

        if ( cookie_field != '' )
            Cookies.set(v, cookie_field, { expires: 30 });

        var curval = Cookies.get(v)

        if (curval != undefined) {
            curval = decodeURIComponent(curval).replace(/[%]/g,' ')

            if ([ 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign', 'gclid' ].indexOf(v) !== -1) {
                handl_utm[v] = curval
            }

            if (v == 'username') {
                //Maybe this should apply to all... We'll see...
                curval = curval.replace(/\+/g, ' ')
            }

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

function getSearchParams(url,k){
    var p={};
    var a = document.createElement('a');
    a.href = url;
    a.search.replace(/[?&]+([^=&]+)=([^&]*)/gi,function(s,k,v){p[k]=v})
    return k?p[k]:p;
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
