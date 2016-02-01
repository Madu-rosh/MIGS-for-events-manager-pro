//add migs redirection
jQuery(document).bind('em_booking_gateway_add_migs', function(event, response){ 
	// called by EM if return JSON contains gateway key, notifications messages are shown by now.
	//alert('hey i run here');
	if(response.result){
		var ppForm = jQuery('<form action="'+response.migs_url+'" method="post" id="em-migs-redirect-form"></form>');
		jQuery.each( response.migs_vars, function(index,value){
			ppForm.append('<input type="hidden" name="'+index+'" value="'+value+'" />');
		});
		ppForm.append('<input id="em-migs-submit" type="submit" style="display:none" />');
		ppForm.appendTo('body').trigger('submit');
	}
});