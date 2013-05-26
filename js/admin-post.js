jQuery(document).ready(function ($) {
  var $descriptiondiv = $('#wpbadger-badge-descriptiondiv');
  if ($descriptiondiv.length > 0) {
    $descriptiondiv.insertBefore('#postdivrich');
    wptitlehint('wpbadger-badge-description');
  }

  var designerOnError = function (response) {
    alert( response.data.message );
  };

  var designerOnSuccess = function (response) {
    if (!response.success) {
      designerOnError( response );
      return;
    }

    $('.inside', '#postimagediv').html( response.data.postimagediv );
    $.fancybox.close();
  };

  /* handle fancebox manually via delegated events to handle when the user
   * removes the feature image */
  $(document).on( 'click', '#wpbadger-badge-designer', function (evt) {
    evt.preventDefault();

    var $this = $(this);

    $.fancybox({
      width: '95%',
      height: '95%',
      minHeight: 680,
      autoSize: false,
      closeClick: false,
      openEffect: 'fade',
      closeEffect: 'none',
      href: $this.attr( 'href' ),
      type: 'iframe',
    });
  });

  /* handle the OpenBadges.me callback. Post the data to WP via ajax */
  $(window).on('message', function (evt) {
    evt = evt.originalEvent;

    if (evt.origin != 'https://www.openbadges.me')
      return;

    if (evt.data.image == 'cancelled') {
      $.fancybox.close();
      return;
    }

    var $designer = $('#wpbadger-badge-designer');
    $.post(
      ajaxurl,
      {
        action: 'wpbadger_badge_designer_publish',
      badge: evt.data,
      post_id: $designer.data( 'post-id' ),
      nonce: $designer.data( 'nonce' ),
      }
    )
    .done( designerOnSuccess )
    .fail( designerOnError );
  });
});

