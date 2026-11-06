<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\ApiKey;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\Station;
use App\Entity\User;
use App\Enums\StationPermissions;
use App\Security\SplitToken;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * The Intro Detector's AzuraCast account: one user whose only role gets View and Media on each enabled station.
 */
final readonly class DetectorAccess
{
    public const string EMAIL = 'intro-detector@sweepers.invalid';
    private const string NAME = 'Intro Detector';
    private const array PERMISSIONS = [StationPermissions::View, StationPermissions::Media];

    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    public function ensureAccount(): User
    {
        $user = $this->user();
        if ($user === null) {
            $user = new User();
            $user->email = self::EMAIL;
            $user->name = self::NAME;
            // Nobody knows this password; the account is used only through its API key.
            $user->setNewPassword(bin2hex(random_bytes(32)));
            $this->em->persist($user);
        }
        if ($this->role($user) === null) {
            $role = new Role();
            $role->name = self::NAME;
            $this->em->persist($role);
            $user->roles->add($role);
        }
        $this->em->flush();

        return $user;
    }

    public function createKey(): string
    {
        $user = $this->ensureAccount();
        $token = SplitToken::generate();
        $this->em->persist(new ApiKey($user, $token, self::NAME . ' (Sweepers plugin)'));
        $this->em->flush();

        return (string)$token;
    }

    public function grant(Station $station): void
    {
        $role = $this->role($this->ensureAccount()) ?? throw new RuntimeException('Detector role is missing.');
        foreach (self::PERMISSIONS as $permission) {
            if (!$this->hasPermission($role, $station, $permission)) {
                $this->em->persist(new RolePermission($role, $station, $permission));
            }
        }
        $this->em->flush();
    }

    public function revoke(Station $station): void
    {
        $user = $this->user();
        $role = $user === null ? null : $this->role($user);
        if ($role === null) {
            return;
        }
        $this->em->createQuery(
            'DELETE FROM App\Entity\RolePermission rp WHERE rp.role = :role AND rp.station = :station'
        )->setParameter('role', $role)->setParameter('station', $station)->execute();
    }

    public function granted(Station $station): bool
    {
        $user = $this->user();
        $role = $user === null ? null : $this->role($user);
        if ($role === null) {
            return false;
        }
        foreach (self::PERMISSIONS as $permission) {
            if (!$this->hasPermission($role, $station, $permission)) {
                return false;
            }
        }

        return true;
    }

    private function user(): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function role(User $user): ?Role
    {
        $role = $user->roles->first();

        return $role instanceof Role ? $role : null;
    }

    private function hasPermission(Role $role, Station $station, StationPermissions $permission): bool
    {
        return $this->em->getRepository(RolePermission::class)->findOneBy([
            'role' => $role,
            'station' => $station,
            'action_name' => $permission->value,
        ]) !== null;
    }
}
