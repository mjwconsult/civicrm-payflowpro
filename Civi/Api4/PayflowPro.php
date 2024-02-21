<?php
namespace Civi\Api4;

/**
 * PayflowPro API
 *
 * @package Civi\Api4
 */
class PayflowPro extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }


}
