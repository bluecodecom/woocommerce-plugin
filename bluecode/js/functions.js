
function doInitPayment(orderid, url) {
  jQuery.ajax({
      url : bluecode_load.ajax_url,
      type: 'post',
      data: {
          action : 'init_payment',
          orderid: orderid
      },
      dataType: 'json',
      success: function(result) {
        //console.log(result);
        if( result.state == 1 ) {
          Bluecode.initiate_payment({
            "ecom_id": result.data.checkin,
            "x-success": result.data.success,
            "x-error": result.data.fail,
            "x-cancel": result.data.cancel
          });
        }
        else {
          //console.log(result.error);
          alert( result.error );
          location.href = url;
        }
      }
  });
}