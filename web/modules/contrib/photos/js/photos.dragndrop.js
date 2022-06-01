/**
 * @file
 * Sortable to rearrange photos by weight in album.
 */

(function ($, Drupal, drupalSettings, Sortable) {
  'use strict';

  Drupal.behaviors.photosDragNDrop = {
    attach(context) {

      const photosSortable = document.getElementById('photos-sortable');
      if (photosSortable) {
        Sortable.create(photosSortable, {
          store: {
            set: function (sortable) {
              // Save new order.
              const sortedIDs = sortable.toArray();
              const sortUrl = drupalSettings.path.baseUrl + 'photos/ajax/rearrange';
              const postData = {
                order: sortedIDs,
                album_id: drupalSettings.photos.album_id,
                uid: drupalSettings.photos.uid,
                type: drupalSettings.photos.sort
              };
              const $photosSrotUpdatesSelector = $('#photos-sort-updates');
              function photosSortUpdateComplate() {
                $photosSrotUpdatesSelector.show();
                $photosSrotUpdatesSelector.delay(500).fadeOut(500);
              }
              $photosSrotUpdatesSelector.load(sortUrl, postData, photosSortUpdateComplate());
            }
          }
        });
      }

    }
  };

}(jQuery, Drupal, drupalSettings, Sortable));
