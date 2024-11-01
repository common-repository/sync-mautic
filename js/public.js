jQuery(document).ready(function ($) {

  let options = newsletter_signup_object;

  $(document).on("click", ".newsletter-signup-subscribe", function() {
    let $newsletterSignup = $(this).closest(".newsletter-signup");

    let email = $newsletterSignup.find(".email").val();
    let tag   = $newsletterSignup.find(".tag").val();
    let data  = {};

    if (email) {
      data.email = email;
    }

    if (tag) {
      data.tag = tag;
    }

    $newsletterSignup.find(".newsletter-signup-responses p.success").text('');
    $newsletterSignup.find(".newsletter-signup-responses p.error").text('');
    $newsletterSignup.find(".newsletter-signup-subscribe").addClass('loading');

    $.ajax({
      type: "POST",
      url: options.site_url + "/wp-json/sync-mautic/v1/add-lead/",
      contentType: 'application/json',
      data: JSON.stringify(data),
      success: function (response) {
        $newsletterSignup.find(".newsletter-signup-subscribe").removeClass('loading');
        $newsletterSignup.find(".newsletter-signup-responses p.success").text("Thank you for signing up!");
      },
      error: function (xhr, textStatus, errorThrown) {
        if (xhr.responseText) {
          var errorResponse = JSON.parse(xhr.responseText);
          console.error('Response Error:', errorResponse.data);
          $newsletterSignup.find(".newsletter-signup-subscribe").removeClass('loading');
          $newsletterSignup.find(".newsletter-signup-responses p.error").text(errorResponse.data)
          
        } else {
          $newsletterSignup.find(".newsletter-signup-subscribe").removeClass('loading');
          $newsletterSignup.find(".newsletter-signup-responses p.error").text("A server error has occured")
        }
      }
    });
  });
  
});