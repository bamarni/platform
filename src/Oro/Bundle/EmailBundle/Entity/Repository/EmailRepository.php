<?php

namespace Oro\Bundle\EmailBundle\Entity\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;

use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\UserBundle\Entity\User;

class EmailRepository extends EntityRepository
{
    /**
     * Gets emails by ids
     *
     * @param int[] $ids
     *
     * @return Email[]
     */
    public function findEmailsByIds($ids)
    {
        $queryBuilder = $this->createQueryBuilder('e');
        $criteria     = new Criteria();
        $criteria->where(Criteria::expr()->in('id', $ids));
        $criteria->orderBy(['sentAt' => Criteria::DESC]);
        $queryBuilder->addCriteria($criteria);
        $result = $queryBuilder->getQuery()->getResult();

        return $result;
    }

    /**
     * Gets email by Message-ID
     *
     * @param string $messageId
     *
     * @return Email|null
     */
    public function findEmailByMessageId($messageId)
    {
        return $this->createQueryBuilder('e')
            ->where('e.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get $limit last emails
     *
     * @param User         $user
     * @param Organization $organization
     * @param int          $limit
     * @param int|null     $folderId
     *
     * @return mixed
     */
    public function getNewEmails(User $user, Organization $organization, $limit, $folderId)
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e, eu.seen')
            ->leftJoin('e.emailUsers', 'eu')
            ->where($this->getAclWhereCondition($user, $organization))
            ->groupBy('e, eu.seen')
            ->orderBy('eu.seen', 'ASC')
            ->addOrderBy('e.sentAt', 'DESC')
            ->setParameter('organization', $organization)
            ->setParameter('owner', $user)
            ->setMaxResults($limit);

        if ($folderId > 0) {
            $qb->leftJoin('eu.folders', 'f')
               ->andWhere('f.id = :folderId')
               ->setParameter('folderId', $folderId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get count new emails
     *
     * @param User         $user
     * @param Organization $organization
     * @param int|null     $folderId
     *
     * @return mixed
     */
    public function getCountNewEmails(User $user, Organization $organization, $folderId = null)
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e)')
            ->leftJoin('e.emailUsers', 'eu')
            ->where($this->getAclWhereCondition($user, $organization))
            ->andWhere('eu.seen = :seen')
            ->setParameter('organization', $organization)
            ->setParameter('owner', $user)
            ->setParameter('seen', false);

        if ($folderId !== null && $folderId > 0) {
            $qb->leftJoin('eu.folders', 'f')
                ->andWhere('f.id = :folderId')
                ->setParameter('folderId', $folderId);
        }

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get count new emails per folders
     *
     * @param User         $user
     * @param Organization $organization
     * @return array
     */
    public function getCountNewEmailsPerFolders(User $user, Organization $organization)
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e) num, f.id')
            ->leftJoin('e.emailUsers', 'eu')
            ->where($this->getAclWhereCondition($user, $organization))
            ->andWhere('eu.seen = :seen')
            ->setParameter('organization', $organization)
            ->setParameter('owner', $user)
            ->setParameter('seen', false)
            ->leftJoin('eu.folders', 'f')
            ->groupBy('f.id');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get email entities by owner entity
     *
     * @param object $entity
     * @param string $ownerColumnName
     *
     * @return array
     */
    public function getEmailsByOwnerEntity($entity, $ownerColumnName)
    {
        $emailFoundInRecipients = $this
            ->createQueryBuilder('e')
            ->join('e.recipients', 'r')
            ->join('r.emailAddress', 'ea')
            ->andWhere("ea.$ownerColumnName = :contactId")
            ->andWhere('ea.hasOwner = :hasOwner')
            ->setParameter('contactId', $entity->getId())
            ->setParameter('hasOwner', true)
            ->getQuery()->getResult();

        $emailFoundInFrom = $this
            ->createQueryBuilder('e')
            ->join('e.fromEmailAddress', 'ea')
            ->andWhere("ea.$ownerColumnName = :contactId")
            ->andWhere('ea.hasOwner = :hasOwner')
            ->setParameter('contactId', $entity->getId())
            ->setParameter('hasOwner', true)
            ->getQuery()->getResult();

        return array_merge($emailFoundInRecipients, $emailFoundInFrom);
    }

    /**
     * @param User         $user
     * @param Organization $organization
     *
     * @return \Doctrine\ORM\Query\Expr\Orx
     */
    protected function getAclWhereCondition(User $user, Organization $organization)
    {
        $mailboxes = $this->getEntityManager()->getRepository('OroEmailBundle:Mailbox')
            ->findAvailableMailboxIds($user, $organization);

        $expr = $this->getEntityManager()->createQueryBuilder()->expr();

        $andExpr = $expr->andX(
            'eu.owner = :owner',
            'eu.organization = :organization'
        );

        if ($mailboxes) {
            return $expr->orX(
                $andExpr,
                $expr->in('eu.mailboxOwner', $mailboxes)
            );
        } else {
            return $andExpr;
        }
    }
}
