UPGRADE FROM 1.5 to 1.6
=======================

####OroOrganizationBundle:
- Removed Twig/OrganizationExtension as organization selector has been removed from login screen

####OroSearchBundle:
 - `Oro\Bundle\SearchBundle\Query\Result\Item` entity field `recordText` marked as deprecated and will be removed in 1.7 version.
 - `Oro\Bundle\SearchBundle\Engine\Orm\BaseDriver::search` return an array filled with array representation of `Oro\Bundle\SearchBundle\Entity\Item`