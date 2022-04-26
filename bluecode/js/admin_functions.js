const { __, _x, _n, _nx } = wp.i18n;

( function( $ ) {
  $( function() {
    $(".oauth2_authorize").prop("value", __("Authorize", "bluecode"));

    $( '.oauth2_authorize' ).click( function() {
      wp.ajax.post( 'load_oauth2_data', {
          nonce: ajax_config.nonce
        })
        .done(function (result) {
          console.log(result);

          if( result && result.result == 'ok' ) {
            window.open( result.getUrl, '_blank', 'toolbar=no,location=no,status=yes,menubar=no,scrollbars=yes,resizable=yes,width=500,height=800' );
          }
          else {
            console.log("fail " + result.result);
            alert( __("You need to fill in all fields and save the configuration before attempting the authorization.", "bluecode") );
          }
        })
        .fail(function (result) {
          console.log("fail " + result);
        });
    });
  });
})( jQuery );
