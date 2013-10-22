/* global wpAjax */
(function($){
	$(document).ready(function(){
		$('a.unfeature_post').click(function() {
			var parent_row = $(this).parents('tr'),
			post_id = $(this).data('id'),
			nonce = $(this).data('nonce'),
			type = $(this).data('type'),
			data = {
				action : 'unfeature_post',
				post_id : post_id,
				type : type,
				_wpnonce : nonce
			};

			$.post( ajaxurl, data, function (r) {
				var res = wpAjax.parseAjaxResponse(r, 'ajax-response');
				if( ! res ){
					return;
				}

				parent_row.fadeOut(300, function(){
					$(this).remove();
				});
			} );
		});

		if($('#sortable_featured_posts_list').is('*')){
			var order = $('#featured_ids_order'),
			save = $('#featured_order_publish'),
			updated = false;

			$('a.sort-post').click(function(e){
				e.preventDefault();
				return false;
			});

			$('#sortable_featured_posts_list tbody').sortable({
				handle : 'a.sort-post',
				opacity: 0.6,
				update: function() {
					var newOrder = [],
					trClass;

					$(this).children('tr').each(function(index, el){
						trClass = ( (index % 2) === 0) ? 'alternate post' : 'post';
						$(el).removeClass('alternate post');
						$(el).addClass(trClass);
						newOrder.push($(el).attr('id'));
					});
					order.val(newOrder);
					save.removeAttr('disabled');
					$('#ajax-response').html('');
					updated = true;
				}
			});

			save.on('click', function(e){
				e.preventDefault();
				$('#ajax-response').html('');
				save.attr('disabled', 'disabled');
				updated = false;
				var data = {
					action : 'save_featured_posts_order',
					order : order.val(),
					type : save.data('type'),
					post_type : save.data('post-type'),
					_wpnonce : save.data('nonce')
				};

				$.post( ajaxurl, data, function (r) {
					var res = wpAjax.parseAjaxResponse(r, 'ajax-response');
					if( ! res ){
						return;
					}

					if(res.responses[0].data){
						$('#ajax-response').html('<div id="message" class="updated"><p>' + res.responses[0].data + '</p></div>');
					}
				} );
			});

			window.onbeforeunload = function(){
				if(updated){
					return 'You haven\'t saved the changes you made.';
				}
			};
		}
	});
})(jQuery);