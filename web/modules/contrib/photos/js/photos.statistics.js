/**
 * @file
 * Statistics functionality.
 */

(function($, Drupal, drupalSettings) {
  $(document).ready(() => {
    $.ajax({
      type: 'POST',
      cache: false,
      async: true,
      dataType: 'json',
      url: drupalSettings.photosStatistics.url,
      data: drupalSettings.photosStatistics.data,
      complete: function(data) {
        $('#photos-visits-' + drupalSettings.photosStatistics.data.id).text(Drupal.formatPlural(parseInt(data.responseJSON.count), '1 visit', '@count visits')).removeClass('hidden');
      }
    });
  });
})(jQuery, Drupal, drupalSettings);
