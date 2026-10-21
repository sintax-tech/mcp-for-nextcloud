<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Static analysis host declarations

These files describe host APIs missing from Composer dependencies. They are scanned
by PHPStan only, never required, registered or included in Composer autoload.
The application still uses the actual Nextcloud and optional-app classes at runtime.

The host reference matches the existing API snapshot for Nextcloud 33.0.0 and Deck
1.17.0. OCP itself comes from the exact stable33 commit in `composer.lock` and is
scanned directly from `vendor/nextcloud/ocp`; it is not redeclared here. Talk is
currently dynamically typed and needs no additional declarations for the level-0
gate. Existing native API contract tests remain mandatory for its consumed methods.

The original per-file AGPL-3.0-only or AGPL-3.0-or-later identifiers are preserved.
Both license texts are retained in the source repository’s root `LICENSES/` directory.

## Source and scope

Each file preserves the original namespace, imports, inheritance/interfaces/traits,
constants, properties, class PHPDoc, public/protected method signatures and their
PHPDoc, and any private constructor. Method bodies are empty; other private methods
are omitted. Constructor signatures retain defaults and promotion flags. These are
source-derived signatures, not permissive test doubles or implementations.

The modeled members are exactly those present in each declaration file; each file
links its source and SHA-256 below. Keeping the public/protected surface of a required
class also preserves its interface contracts and inherited API. Four Deck ancestors/
interfaces supplement the 35 directly missing host classes. No synthetic `__call`,
return type, suppression or application alias was introduced. The actual Entity
magic contract remains inherited from OCP where upstream uses it.

## Maintenance

When changing a host reference, compare every declared signature with its pinned
upstream source (including return types, visibility, inheritance, defaults and
class-level PHPDoc), update the recorded digest, and rerun both analysis gates and
`composer test:api`. The API snapshots alone do not contain every return type.
Do not load PHPUnit stubs, override OCP symbols, or add unknown methods to quiet a
warning. Missing classes and misspelled methods must fail analysis. The positive/
negative probes in the execution report verify this distinction.

These declarations and all analysis configurations/caches are excluded from the
production package. They cannot replace real Nextcloud 32–35 integration checks.

## Provenance manifest

Paths below are relative to this directory; all files contain the original SPDX
attribution. Hashes identify the complete upstream source before body removal.

| Declaration | Source | SHA-256 |
| --- | --- | --- |
| [OCA\DAV\AppInfo\PluginManager](symbols/OCA/DAV/AppInfo/PluginManager.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/AppInfo/PluginManager.php) | `78a56d2b52451eb20d8ffeccebcc567c7920a104806b474e0100a0b5055e1e44` |
| [OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin](symbols/OCA/DAV/CalDAV/Auth/CustomPrincipalPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php) | `7d8d9ff4f8964123b4534e990323f16c6f5f620e4276f3b62067187b19fdbe38` |
| [OCA\DAV\CalDAV\CalDavBackend](symbols/OCA/DAV/CalDAV/CalDavBackend.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CalDAV/CalDavBackend.php) | `224f3a0f8fb2293cf84b9a963d3fa1b14ce0addc95479a45b96f011ae1ad1aac` |
| [OCA\DAV\CardDAV\CardDavBackend](symbols/OCA/DAV/CardDAV/CardDavBackend.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/CardDavBackend.php) | `73d8d6be4abfc72ebf6bbb47711c329485cd8e830088d7aa8f483475382728d4` |
| [OCA\DAV\CardDAV\HasPhotoPlugin](symbols/OCA/DAV/CardDAV/HasPhotoPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/HasPhotoPlugin.php) | `168bd7aad06a2766ac661e85207e38c2d9dd38c5750366f33064ff704493a009` |
| [OCA\DAV\CardDAV\ImageExportPlugin](symbols/OCA/DAV/CardDAV/ImageExportPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/ImageExportPlugin.php) | `c3d4ad6e299aa8e03a9590d71994fbcc47d747132c4d8d4917b1003fb7dfc0d8` |
| [OCA\DAV\CardDAV\MultiGetExportPlugin](symbols/OCA/DAV/CardDAV/MultiGetExportPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/MultiGetExportPlugin.php) | `0da1dcedb7622f56f1d2fc232165bfb90592acef2d3d167a5ff8750f1e387eea` |
| [OCA\DAV\CardDAV\PhotoCache](symbols/OCA/DAV/CardDAV/PhotoCache.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/PhotoCache.php) | `c2ced10d16949bf86d0f97a5b2c7732cd50e71300f1de654031d62d201e822ff` |
| [OCA\DAV\CardDAV\Plugin](symbols/OCA/DAV/CardDAV/Plugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Plugin.php) | `f8e87a436df594a6f6c7f9c16fcca69b6c5d2f215f256c59991c0a0ec7ee9757` |
| [OCA\DAV\CardDAV\Security\CardDavRateLimitingPlugin](symbols/OCA/DAV/CardDAV/Security/CardDavRateLimitingPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Security/CardDavRateLimitingPlugin.php) | `ebf9b68e92e7863aa1d6eb0433098da99168b66289abe2941942b7f02ef531a5` |
| [OCA\DAV\CardDAV\Validation\CardDavValidatePlugin](symbols/OCA/DAV/CardDAV/Validation/CardDavValidatePlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Validation/CardDavValidatePlugin.php) | `e3f9c3f0a559b7d7ba8121806ca97ba221ad50f809de0d8f28a19908332e4833` |
| [OCA\DAV\Connector\Sabre\CachingTree](symbols/OCA/DAV/Connector/Sabre/CachingTree.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/CachingTree.php) | `6e71e593b131807e057ee9f9609cae4fb91a69b5d4e956f2ad5eadfca1517412` |
| [OCA\DAV\Connector\Sabre\DavAclPlugin](symbols/OCA/DAV/Connector/Sabre/DavAclPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/DavAclPlugin.php) | `22c857b3bb3e3263ab5b905b0564e405f4111c7e7c1e7d5931ecef2938c02810` |
| [OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin](symbols/OCA/DAV/Connector/Sabre/ExceptionLoggerPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/ExceptionLoggerPlugin.php) | `609fbc224c7e2893cbe31848032d7b5628e45bf8d0e7f2c93bc3f29289d9588c` |
| [OCA\DAV\Connector\Sabre\LockPlugin](symbols/OCA/DAV/Connector/Sabre/LockPlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/LockPlugin.php) | `3da234bea5a5c06eb26e9a22b768642cb4512f7d5cb7b429768152c6adaf1b6a` |
| [OCA\DAV\Connector\Sabre\MaintenancePlugin](symbols/OCA/DAV/Connector/Sabre/MaintenancePlugin.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/MaintenancePlugin.php) | `f2ffc2fe2672eb22e503b302f456e81978afd991f49ff69e5745bdadbb25576b` |
| [OCA\DAV\Connector\Sabre\Server](symbols/OCA/DAV/Connector/Sabre/Server.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/Server.php) | `893c17e3715e593081d0a70338d1eb423d3f79c027e683b217920ad8e069bd08` |
| [OCA\DAV\Events\SabrePluginAuthInitEvent](symbols/OCA/DAV/Events/SabrePluginAuthInitEvent.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Events/SabrePluginAuthInitEvent.php) | `75f2bd380a75b921f67b5227d67a928360a062f15e629f7dc3b22d085f892a0f` |
| [OCA\DAV\RootCollection](symbols/OCA/DAV/RootCollection.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/RootCollection.php) | `dc19078ac0fbaa72d2c290cd0af3ab4ab0be647d9d0da7830d8c491c5825a582` |
| [OCA\Deck\Db\Acl](symbols/OCA/Deck/Db/Acl.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Acl.php) | `2993e84a33f460b887e66103ce7cd29c80885f45d9f6b5fd95e6e5f1de506671` |
| [OCA\Deck\Db\Assignment](symbols/OCA/Deck/Db/Assignment.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Assignment.php) | `d895f7efebc21fbc61ff91c689839ba70604330e49129a9506a1101f469f25bb` |
| [OCA\Deck\Db\AssignmentMapper](symbols/OCA/Deck/Db/AssignmentMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/AssignmentMapper.php) | `e75ba2d2b023b905c8f826bc9c161639ed4ed19e36f0b977273b014fbc836962` |
| [OCA\Deck\Db\Board](symbols/OCA/Deck/Db/Board.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Board.php) | `7dbe4f31193b73f05454f92007f6a5558b6434a0859339d9d886c4f57ed9e68d` |
| [OCA\Deck\Db\BoardMapper](symbols/OCA/Deck/Db/BoardMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/BoardMapper.php) | `c3be762590fb7f490d6f08c27dd9855e10d61d9f19a9e15298eefc7f61864fd4` |
| [OCA\Deck\Db\Card](symbols/OCA/Deck/Db/Card.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Card.php) | `1e8033b2c6fecdb785abdd2678b137be24c855896283f1938e7e112eb8003c31` |
| [OCA\Deck\Db\CardMapper](symbols/OCA/Deck/Db/CardMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/CardMapper.php) | `8b63c07d566ea1728474fed36b4e4951deabece3b11a5eb8c446502c5ef083b4` |
| [OCA\Deck\Db\DeckMapper](symbols/OCA/Deck/Db/DeckMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/DeckMapper.php) | `dc9c90201d973cff23b03bc3e490e6fe91ff9205d0d6b587ad51f07bad6a3837` |
| [OCA\Deck\Db\IPermissionMapper](symbols/OCA/Deck/Db/IPermissionMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/IPermissionMapper.php) | `47dddc631592eac0f7384e524b0f471a9d3e9437989215bceb17e3709b0a5bb3` |
| [OCA\Deck\Db\RelationalEntity](symbols/OCA/Deck/Db/RelationalEntity.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/RelationalEntity.php) | `18c3c141973d1226aed7b1996305a7f3dfbd02684fafc054751e131db385cb24` |
| [OCA\Deck\Db\Stack](symbols/OCA/Deck/Db/Stack.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Stack.php) | `d06c75a4eebe0d24e1f5c77a36e289f24f1fb093e56540a4bd7e1a81cd0a1c35` |
| [OCA\Deck\Db\StackMapper](symbols/OCA/Deck/Db/StackMapper.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/StackMapper.php) | `1a0f763125be937b95bc6ce96d6f7c69db42a8768d6367d87f8ba1e05a7ef0d7` |
| [OCA\Deck\Model\OptionalNullableValue](symbols/OCA/Deck/Model/OptionalNullableValue.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Model/OptionalNullableValue.php) | `3e76040c7abad76497cc1b320d8f98c8dfbb8c7fcb5ebd2a08024ef2ba9fea50` |
| [OCA\Deck\NoPermissionException](symbols/OCA/Deck/NoPermissionException.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/NoPermissionException.php) | `ace598fa0b9a0a77aaeb7eb6ebe26fc371c867c6834bab6443c16076d2f70c28` |
| [OCA\Deck\Service\AssignmentService](symbols/OCA/Deck/Service/AssignmentService.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/AssignmentService.php) | `140552e30b81606dbeb32e875095b5725f034674fe2b00a17bd231d24b645570` |
| [OCA\Deck\Service\BoardService](symbols/OCA/Deck/Service/BoardService.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/BoardService.php) | `7992f1c29faaf3cfbb19f661d761e817778fd6f0212f100a82f3300ceaa401e3` |
| [OCA\Deck\Service\CardService](symbols/OCA/Deck/Service/CardService.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/CardService.php) | `618de50ab7538553973f8ec314d519c8e3fe06d655bf2cec22b260d7846a1fa0` |
| [OCA\Deck\Service\PermissionService](symbols/OCA/Deck/Service/PermissionService.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/PermissionService.php) | `2b3dfd18222f09062269b3e401713fbe3fc9d921445ef6991cfbeb6783743651` |
| [OCA\Deck\Service\StackService](symbols/OCA/Deck/Service/StackService.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/StackService.php) | `b345f8e32109a78b936c30a7a71691f951f04a4649628ac31715351d4081b061` |
| [OCA\Deck\StatusException](symbols/OCA/Deck/StatusException.php) | [pinned source](https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/StatusException.php) | `b283c77c5c11c657fa863fcf1fee68599836816b227016213cca955114c15e13` |
