<?php
// Roles & permissions: role cards (staff, permissions, modules each role opens), create / edit / clone
// roles with a live preview of the screens they unlock, and the permission matrix grouped by module.
// Super Admin is protected: it always holds every permission and cannot be edited, cloned or deleted.
// Only Super Admins change roles; staff with roles.manage can view them. Staff accounts: users.php?type=staff.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-security.php';

$me = require_staff_page();
require_permission('roles.manage');

// Staff accounts moved to their own screen (older links: roles.php?tab=staff).
if (($_GET['tab'] ?? '') === 'staff') {
    redirect_to('users.php?type=staff');
}
// The Security tab bar links "Roles" as roles.php?tab=roles: role editor pages (roles.php?id=…) belong to it too.
$_GET['tab'] = (string) ($_GET['tab'] ?? 'roles');

const PERM_MODULE_LABELS = [
    'dashboard' => 'Dashboard', 'reports' => 'Reports & exports', 'courses' => 'Courses & learning', 'resources' => 'Free resources',
    'exams' => 'Mock tests & quizzes', 'live' => 'Live classes', 'users' => 'Students', 'finance' => 'Payments & finance',
    'enquiries' => 'Leads & enquiries', 'support' => 'Support desk', 'notifications' => 'Emails & notifications',
    'content' => 'Website, blog & SEO', 'settings' => 'Settings & integrations', 'roles' => 'Staff & roles', 'logs' => 'Security logs',
];

$tabs = ['roles' => ['label' => 'Roles', 'icon' => 'key'], 'matrix' => ['label' => 'Permission matrix', 'icon' => 'grid']];
$tab = current_tab($tabs);
$isSuper = is_super_admin($me);
$roles = db_all('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = r.id AND u.user_type = \'staff\' AND u.status = \'active\') AS members
                 FROM roles r ORDER BY r.is_system DESC, r.id');
$rolesById = [];
foreach ($roles as $r) {
    $rolesById[(int) $r['id']] = $r;
}
$superId = (int) (array_column($roles, 'id', 'slug')['super_admin'] ?? 0);
$perms = db_all('SELECT * FROM permissions ORDER BY FIELD(module, ' . implode(',', array_fill(0, count(PERM_MODULE_LABELS), '?')) . '), id', array_keys(PERM_MODULE_LABELS));
$permsById = [];
foreach ($perms as $p) {
    $permsById[(int) $p['id']] = $p;
}
$rolePerms = sec_role_permissions();

/** Screens (menu items) that need each permission: slug => [labels]. */
$screensByPerm = [];
foreach (admin_menu() as $m) {
    foreach ($m[3] as $item) {
        if (!empty($item[2])) {
            $screensByPerm[$item[2]][] = $item[0];
        }
    }
}

/** Permission ids posted as perm[] that exist. */
function posted_perm_ids(array $permsById): array
{
    return array_values(array_filter(array_unique(array_map('intval', array_keys((array) ($_POST['perm'] ?? [])))), fn($id) => isset($permsById[$id])));
}

// ------------------------------------------------------------------ actions (Super Admin only)
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    if (!$isSuper) {
        flash('error', 'Only a Super Admin can change roles and permissions.');
        redirect_to('roles.php');
    }

    if ($act === 'save_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = $id ? ($rolesById[$id] ?? null) : null;
        if ($id && !$role) {
            flash('error', 'Role not found.');
            redirect_to('roles.php');
        }
        if ($id === $superId) {
            flash('error', 'Super Admin always has every permission and cannot be changed.');
            redirect_to('roles.php');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
        $chosen = posted_perm_ids($permsById);
        if ($role && $role['is_system']) {
            $name = $role['name']; // built-in roles keep their name (other code refers to their slug)
        }
        $slug = str_replace('-', '_', slugify($name));
        $back = $id ? 'roles.php?id=' . $id : 'roles.php?action=new';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60 || $slug === '') {
            flash('error', 'Role name must be 3-60 characters.');
            redirect_to($back);
        }
        if (db_value('SELECT 1 FROM roles WHERE (slug = ? OR name = ?) AND id <> ?', [$slug, $name, $id])) {
            flash('error', 'Another role already uses the name “' . $name . '”.');
            redirect_to($back);
        }
        if (!$chosen) {
            flash('error', 'Give the role at least one permission.');
            redirect_to($back);
        }
        $before = $id ? array_map('intval', array_column(db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$id]), 'permission_id')) : [];
        $id = db_transaction(function () use ($id, $role, $name, $slug, $desc, $chosen) {
            if ($id) {
                if ($role['is_system']) {
                    db_query('UPDATE roles SET description = ? WHERE id = ?', [$desc ?: null, $id]);
                } else {
                    db_query('UPDATE roles SET name = ?, slug = ?, description = ? WHERE id = ?', [$name, $slug, $desc ?: null, $id]);
                }
                db_query('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            } else {
                db_query('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 0)', [$slug, $name, $desc ?: null]);
                $id = (int) db()->lastInsertId();
            }
            foreach ($chosen as $pid) {
                db_query('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $pid]);
            }
            return $id;
        });
        $slugs = fn(array $ids) => array_values(array_map(fn($p) => $permsById[$p]['slug'] ?? '?', $ids));
        audit_log($role ? 'role_updated' : 'role_created', 'roles', $slug, ['added' => $slugs(array_diff($chosen, $before)), 'removed' => $slugs(array_diff($before, $chosen))], 'critical');
        flash('success', $role ? 'Role saved. Staff with this role get the new access on their next page load.' : 'Role “' . $name . '” created. Assign it to staff in Staff accounts.');
        redirect_to('roles.php?id=' . $id);
    }

    if ($act === 'delete_role') {
        $role = $rolesById[(int) ($_POST['id'] ?? 0)] ?? null;
        if (!$role || $role['is_system']) {
            flash('error', 'Built-in roles cannot be deleted.');
        } elseif (db_value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$role['id']])) {
            flash('error', 'Remove this role from every staff account (including deactivated ones) before deleting it.');
        } else {
            db_transaction(function () use ($role) {
                db_query('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
                db_query('DELETE FROM roles WHERE id = ?', [$role['id']]);
            });
            audit_log('role_deleted', 'roles', $role['slug'], [], 'critical');
            flash('success', 'Role “' . $role['name'] . '” deleted.');
        }
        redirect_to('roles.php');
    }

    if ($act === 'matrix') {
        $grid = (array) ($_POST['perm'] ?? []);
        db_transaction(function () use ($grid, $superId, $rolesById, $permsById) {
            db_query('DELETE FROM role_permissions WHERE role_id <> ?', [$superId]);
            foreach ($grid as $rid => $list) {
                if ((int) $rid === $superId || !isset($rolesById[(int) $rid])) {
                    continue;
                }
                foreach (array_keys((array) $list) as $pid) {
                    if (isset($permsById[(int) $pid])) {
                        db_query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [(int) $rid, (int) $pid]);
                    }
                }
            }
        });
        audit_log('permission_matrix_changed', 'roles', null, [], 'critical');
        flash('success', 'Permissions saved for every role.');
        redirect_to('roles.php?tab=matrix');
    }
    flash('error', 'Unknown action.');
    redirect_to('roles.php');
}

$moduleChips = function (array $slugs, bool $super, int $max = 99): string {
    $mods = sec_modules_for($slugs, $super);
    $h = '';
    foreach (array_slice($mods, 0, $max) as [$label, $ico, $n, $all]) {
        $h .= '<span class="sa-chip" title="' . $n . ' of ' . $all . ' screens">' . icon($ico) . ' ' . e($label) . '</span>';
    }
    if (count($mods) > $max) {
        $h .= '<span class="sa-chip">+' . (count($mods) - $max) . ' more</span>';
    }
    return $h;
};

// ------------------------------------------------------------------ role editor (view / edit / new / clone)
$editId = (int) ($_GET['id'] ?? 0);
if ($editId || ($_GET['action'] ?? '') === 'new') {
    $role = $editId ? ($rolesById[$editId] ?? null) : null;
    if ($editId && !$role) {
        flash('error', 'Role not found.');
        redirect_to('roles.php');
    }
    $from = $role ? null : ($rolesById[(int) ($_GET['from'] ?? 0)] ?? null);
    if ($from && (int) $from['id'] === $superId) {
        $from = null; // Super Admin cannot be cloned
    }
    $isSuperRole = $role && (int) $role['id'] === $superId;
    $editable = $isSuper && !$isSuperRole;
    $haveSlugs = $isSuperRole ? array_column($perms, 'slug') : ($rolePerms[(int) (($role ?? $from)['id'] ?? 0)] ?? []);
    $name = $role['name'] ?? ($from ? 'Copy of ' . $from['name'] : '');
    $desc = (string) ($role['description'] ?? ($from['description'] ?? ''));
    $members = $role ? db_all("SELECT u.id, u.name, u.email, u.status FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = ? AND u.user_type = 'staff' ORDER BY u.status = 'active' DESC, u.name", [$role['id']]) : [];
    $title = $role ? $role['name'] : ($from ? 'Clone ' . $from['name'] : 'New role');
    admin_start($title, null, 'roles', ['head' => true, 'crumbs' => '<a href="roles.php">Roles &amp; permissions</a> / ' . e($title),
        'subtitle' => $isSuperRole ? 'Protected: Super Admin always holds every permission, including roles, settings and backups.'
            : ($role ? ($role['is_system'] ? 'Built-in role. You can change its permissions and description.' : 'Custom role.') : 'Choose exactly what this role may open and change.'),
        'actions' => '<a class="btn btn-light" href="roles.php">' . icon('left') . ' All roles</a>'
            . ($role && !$isSuperRole && $isSuper ? '<a class="btn btn-light" href="roles.php?action=new&amp;from=' . (int) $role['id'] . '">' . icon('copy') . ' Clone</a>' : '')]);
    if (!$isSuper) {
        echo '<div class="alert alert-info">' . icon('info') . '<span>You can view roles. Only a Super Admin can change them.</span></div>';
    }
    $dis = $editable ? '' : ' disabled';
    ?>
<form method="post" class="split-2-1 rl-editor" novalidate data-dirty-check data-role-editor>
  <?= csrf_field() ?><input type="hidden" name="_action" value="save_role"><input type="hidden" name="id" value="<?= (int) ($role['id'] ?? 0) ?>">
  <div class="stack-y">
    <section class="card">
      <div class="card-head"><h2><?= icon('key') ?> Role details</h2><?= $isSuperRole ? '<span class="badge b-purple">' . icon('lock') . ' Protected</span>' : ($role && $role['is_system'] ? '<span class="badge b-gray">Built-in</span>' : '<span class="badge b-blue">Custom</span>') ?></div>
      <div class="card-pad form">
        <div class="field"><label for="rl-name">Role name</label><input id="rl-name" name="name" value="<?= e($name) ?>" maxlength="60" required placeholder="e.g. Faculty - Polity"<?= $dis || ($role && $role['is_system']) ? ' disabled' : '' ?>><?= $role && $role['is_system'] ? '<div class="hint">Built-in role names are fixed.</div>' : '' ?></div>
        <div class="field"><label for="rl-desc">Description</label><input id="rl-desc" name="description" value="<?= e($desc) ?>" maxlength="255" placeholder="What this person does"<?= $dis ?>></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('shield') ?> Permissions</h2><p class="muted"><?= $isSuperRole ? 'Every permission, always.' : 'Tick a module to select all of its permissions.' ?></p></div><span class="badge b-blue" data-perm-count><?= count($haveSlugs) ?> of <?= count($perms) ?></span></div>
      <div class="rl-modules">
        <?php $byMod = []; foreach ($perms as $p) { $byMod[$p['module']][] = $p; } ?>
        <?php foreach ($byMod as $mod => $list): $on = count(array_filter($list, fn($p) => in_array($p['slug'], $haveSlugs, true))); ?>
          <fieldset class="rl-mod">
            <legend class="rl-mod-head"><label class="check"><input type="checkbox" data-mod-all="<?= e($mod) ?>"<?= $on === count($list) ? ' checked' : '' ?><?= $dis ?>> <strong><?= e(PERM_MODULE_LABELS[$mod] ?? ucfirst($mod)) ?></strong></label><small class="muted"><?= $on ?>/<?= count($list) ?></small></legend>
            <?php foreach ($list as $p): $screens = $screensByPerm[$p['slug']] ?? []; ?>
              <label class="rl-perm"><input type="checkbox" name="perm[<?= (int) $p['id'] ?>]" value="1" data-mod="<?= e($mod) ?>" data-slug="<?= e($p['slug']) ?>"<?= in_array($p['slug'], $haveSlugs, true) ? ' checked' : '' ?><?= $dis ?>>
                <span><strong><?= e($p['name']) ?></strong> <code><?= e($p['slug']) ?></code>
                <?php if ($screens): ?><small>Opens: <?= e(implode(', ', array_slice(array_unique($screens), 0, 4))) ?><?= count(array_unique($screens)) > 4 ? ' +' . (count(array_unique($screens)) - 4) . ' more' : '' ?></small><?php else: ?><small>Used for actions inside other screens.</small><?php endif; ?></span></label>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <?php if ($editable): ?><div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><a class="btn btn-light" href="roles.php">Cancel</a><button class="btn btn-primary"<?= $members ? ' data-confirm="Save this role? ' . e(plural_n(count($members), 'staff member')) . ' ' . (count($members) === 1 ? 'gets' : 'get') . ' the new access immediately."' : '' ?>><?= icon('check') ?> <?= $role ? 'Save role' : 'Create role' ?></button></div><?php endif; ?>
    </section>
  </div>

  <aside class="stack-y">
    <section class="card sticky-aside">
      <div class="card-head"><div><h2><?= icon('eye') ?> Screens this role opens</h2><p class="muted">Sidebar modules and tabs a staff member with only this role sees.</p></div></div>
      <div class="card-pad">
        <div class="sa-mod-chips" data-mod-preview>
          <?php foreach (sec_menu_permission_map() as $m): $vis = $isSuperRole || $m['open'] || array_intersect($m['perms'], $haveSlugs); ?>
            <span class="sa-chip<?= $vis ? '' : ' off' ?>" data-perms="<?= e(implode(' ', $m['perms'])) ?>" data-open="<?= $m['open'] ? '1' : '0' ?>"><?= icon($m['icon']) ?> <?= e($m['label']) ?></span>
          <?php endforeach; ?>
        </div>
        <p class="muted" style="font-size:12.5px;margin:12px 0 0">Faded modules stay hidden, and their pages answer with “You don’t have access” even when opened by URL.</p>
      </div>
    </section>
    <?php if ($role): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('users') ?> Staff with this role</h2><a href="users.php?type=staff&amp;role=<?= e($role['slug']) ?>">Manage</a></div>
      <?php if (!$members): ?><div class="empty"><p>Nobody has this role yet.</p><a class="btn btn-light btn-sm" href="users.php?type=staff&amp;action=new"><?= icon('plus') ?> Add staff member</a></div><?php else: ?>
      <ul class="sa-feed">
        <?php foreach ($members as $mbr): ?><li><span class="sa-dot <?= $mbr['status'] === 'active' ? 'ok' : 'bad' ?>" aria-hidden="true"></span><span><a href="users.php?type=staff&amp;id=<?= (int) $mbr['id'] ?>"><strong><?= e($mbr['name']) ?></strong></a><small><?= e($mbr['email']) ?><?= $mbr['status'] !== 'active' ? ' · deactivated' : '' ?></small></span></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </aside>
</form>
<?php if ($role && $isSuper && !$role['is_system']): ?>
<section class="card mt rl-danger">
  <div class="card-pad">
    <div><strong>Delete this role</strong><p class="muted"><?= $members || db_value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$role['id']]) ? 'Remove it from every staff account first.' : 'Nobody has this role, so it can be deleted safely.' ?></p></div>
    <?= !db_value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$role['id']]) ? action_form('delete_role', ['id' => $role['id']], icon('trash') . ' Delete role', 'btn btn-danger', 'Delete the “' . $role['name'] . '” role?') : '' ?>
  </div>
</section>
<?php endif; ?>
<script>
(function () {
  var form = document.querySelector('[data-role-editor]');
  if (!form) { return; }
  var boxes = [].slice.call(form.querySelectorAll('input[data-slug]'));
  function sync() {
    var have = {};
    boxes.forEach(function (b) { if (b.checked) { have[b.dataset.slug] = true; } });
    form.querySelectorAll('[data-mod-all]').forEach(function (all) {
      var mine = boxes.filter(function (b) { return b.dataset.mod === all.dataset.modAll; });
      var n = mine.filter(function (b) { return b.checked; }).length;
      all.checked = n === mine.length; all.indeterminate = n > 0 && n < mine.length;
      var c = all.closest('legend').querySelector('small'); if (c) { c.textContent = n + '/' + mine.length; }
    });
    var count = form.querySelector('[data-perm-count]');
    if (count) { count.textContent = Object.keys(have).length + ' of ' + boxes.length; }
    form.querySelectorAll('[data-mod-preview] [data-perms]').forEach(function (chip) {
      var on = chip.dataset.open === '1' || chip.dataset.perms.split(' ').some(function (p) { return have[p]; });
      chip.classList.toggle('off', !on);
    });
  }
  form.addEventListener('change', function (e) {
    var all = e.target.closest('[data-mod-all]');
    if (all) { boxes.forEach(function (b) { if (b.dataset.mod === all.dataset.modAll && !b.disabled) { b.checked = all.checked; } }); }
    sync();
  });
  sync();
})();
</script>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------ list + matrix
admin_start('Roles & Permissions', null, 'roles', ['head' => true, 'subtitle' => 'Every admin page and action checks these permissions on the server.',
    'actions' => '<a class="btn btn-light" href="users.php?type=staff">' . icon('users') . ' Staff accounts</a>'
        . ($isSuper ? '<a class="btn btn-primary" href="roles.php?action=new">' . icon('plus') . ' New role</a>' : '')]);
echo admin_tabs($tabs, $tab, ['label' => 'Roles sections']);

if ($tab === 'matrix') {
    $editable = $isSuper;
    $byMod = [];
    foreach ($perms as $p) {
        $byMod[$p['module']][] = $p;
    }
    $have = [];
    foreach ($rolePerms as $rid => $slugs) {
        foreach ($slugs as $s) {
            $have[$rid][$s] = true;
        }
    }
    ?>
<form method="post" class="card rl-matrix" data-matrix<?= $editable ? ' data-dirty-check' : '' ?>>
  <?= csrf_field() ?><input type="hidden" name="_action" value="matrix">
  <div class="card-head"><div><h2><?= icon('grid') ?> Permission matrix</h2><p class="muted">Rows are permissions grouped by module; columns are roles. Tick a module row to give a role the whole module.</p></div></div>
  <div class="table-wrap"><table class="t">
    <thead><tr><th scope="col">Permission</th><?php foreach ($roles as $r): ?><th scope="col" class="rl-col"><a href="roles.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><?= (int) $r['id'] === $superId ? '<small>All, always</small>' : '<small>' . (int) $r['members'] . ' staff</small>' ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($byMod as $mod => $list): ?>
      <tr class="rl-modrow"><th scope="rowgroup"><?= e(PERM_MODULE_LABELS[$mod] ?? ucfirst($mod)) ?></th>
        <?php foreach ($roles as $r): $rid = (int) $r['id']; $n = $rid === $superId ? count($list) : count(array_filter($list, fn($p) => !empty($have[$rid][$p['slug']]))); ?>
          <td class="rl-cell"><input type="checkbox" aria-label="<?= e($r['name'] . ': all of ' . (PERM_MODULE_LABELS[$mod] ?? $mod)) ?>" data-mod-all="<?= e($mod) ?>" data-role="<?= $rid ?>"<?= $n === count($list) ? ' checked' : '' ?><?= $rid === $superId || !$editable ? ' disabled' : '' ?>></td>
        <?php endforeach; ?></tr>
      <?php foreach ($list as $p): ?>
        <tr><td><span class="rl-pname"><?= e($p['name']) ?></span><code class="rl-slug"><?= e($p['slug']) ?></code></td>
          <?php foreach ($roles as $r): $rid = (int) $r['id']; $checked = $rid === $superId || !empty($have[$rid][$p['slug']]); ?>
            <td class="rl-cell"><input type="checkbox" aria-label="<?= e($r['name'] . ': ' . $p['name']) ?>" name="perm[<?= $rid ?>][<?= (int) $p['id'] ?>]" value="1" data-mod="<?= e($mod) ?>" data-role="<?= $rid ?>"<?= $checked ? ' checked' : '' ?><?= $rid === $superId || !$editable ? ' disabled' : '' ?>></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="form-actions">
    <span class="muted" style="margin-right:auto">Super Admin always has every permission.<?= $editable ? '' : ' Only a Super Admin can edit permissions.' ?></span>
    <?php if ($editable): ?><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary" data-confirm="Save permission changes for all roles? Staff get the new access immediately."><?= icon('check') ?> Save permissions</button><?php endif; ?>
  </div>
</form>
<script>
(function () {
  var f = document.querySelector('[data-matrix]');
  if (!f) { return; }
  function cells(mod, role) { return [].slice.call(f.querySelectorAll('input[name][data-mod="' + mod + '"][data-role="' + role + '"]')); }
  function sync() {
    f.querySelectorAll('[data-mod-all]').forEach(function (a) {
      var c = cells(a.dataset.modAll, a.dataset.role), n = c.filter(function (x) { return x.checked; }).length;
      a.checked = n === c.length; a.indeterminate = n > 0 && n < c.length;
    });
  }
  f.addEventListener('change', function (e) {
    var a = e.target.closest('[data-mod-all]');
    if (a) { cells(a.dataset.modAll, a.dataset.role).forEach(function (x) { if (!x.disabled) { x.checked = a.checked; } }); }
    sync();
  });
  sync();
})();
</script>
    <?php
    admin_end();
    exit;
}

// Role cards
?>
<div class="rl-grid">
  <?php foreach ($roles as $r): $rid = (int) $r['id']; $super = $rid === $superId; $slugs = $rolePerms[$rid] ?? []; ?>
  <article class="card rl-card<?= $super ? ' is-super' : '' ?>">
    <div class="rl-card-top">
      <span class="ico <?= $super ? 'tone-purple' : ($r['is_system'] ? 'tone-blue' : 'tone-teal') ?>"><?= icon($super ? 'shield' : 'key') ?></span>
      <div class="rl-card-t">
        <h2><a href="roles.php?id=<?= $rid ?>"><?= e($r['name']) ?></a></h2>
        <div class="rl-badges"><?= $super ? '<span class="badge b-purple">' . icon('lock') . ' Protected</span>' : ($r['is_system'] ? '<span class="badge b-gray">Built-in</span>' : '<span class="badge b-blue">Custom</span>') ?></div>
      </div>
    </div>
    <p class="muted rl-desc"><?= e((string) ($r['description'] ?: 'No description.')) ?></p>
    <div class="rl-stats">
      <a href="users.php?type=staff&amp;role=<?= e($r['slug']) ?>"><b><?= (int) $r['members'] ?></b> active staff</a>
      <span><b><?= $super ? 'All' : count($slugs) ?></b> permissions</span>
    </div>
    <div class="sa-mod-chips"><?= $super ? '<span class="sa-chip">' . icon('sparkles') . ' Every module, setting and backup</span>' : ($slugs ? $moduleChips($slugs, false, 6) : '<span class="muted" style="font-size:12.5px">No permissions yet - only their own profile and 2FA.</span>') ?></div>
    <div class="rl-actions">
      <a class="btn btn-light btn-sm" href="roles.php?id=<?= $rid ?>"><?= icon($isSuper && !$super ? 'edit' : 'eye') ?> <?= $isSuper && !$super ? 'Edit' : 'View' ?></a>
      <?php if ($isSuper && !$super): ?><a class="btn btn-light btn-sm" href="roles.php?action=new&amp;from=<?= $rid ?>"><?= icon('copy') ?> Clone</a><?php endif; ?>
      <?php if ($isSuper && !$r['is_system'] && !db_value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$rid])): ?><?= action_form('delete_role', ['id' => $rid], icon('trash') . ' Delete', 'btn btn-danger btn-sm', 'Delete the “' . $r['name'] . '” role?') ?><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
  <?php if ($isSuper): ?>
  <a class="card rl-card rl-new" href="roles.php?action=new"><span class="ico tone-blue"><?= icon('plus') ?></span><strong>Create a custom role</strong><span class="muted">For example “Faculty - Polity” with only lessons and mock tests, or “Counsellor” with leads only.</span></a>
  <?php endif; ?>
</div>
<?php admin_end();
