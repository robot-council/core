<?php

declare(strict_types=1);

/*
 * The dashboard's vocabulary, explained in plain words (#402).
 *
 * Every term of art a page shows is listed in that page's "What the words on this page mean"
 * section, and this file is where each one is explained. A host can reword any entry by publishing
 * the package's translations.
 *
 * **The words live here rather than in a view or a class, and that placement is deliberate.**
 * Tailwind scans `resources/views` for class names and cannot tell a sentence from an attribute, so
 * forty paragraphs of prose in a view would put whatever daisyUI class they happen to contain into
 * the shipped stylesheet. `src/` refuses class-shaped string literals for the same reason. Neither
 * scanner reads this directory.
 *
 * Each entry is `term`, the word as the page shows it, and `means`, one or two plain sentences that
 * lead with what the word is.
 */

return [
    // The fleet and who is in it
    'agent' => [
        'term' => 'Agent',
        'means' => 'An AI coding assistant, such as Claude Code, working on a developer\'s machine. Each one connects to the fleet as a session.',
    ],
    'fleet' => [
        'term' => 'Fleet',
        'means' => 'Every agent connected to this server, across all developers.',
    ],
    'session' => [
        'term' => 'Session',
        'means' => 'One agent\'s connection to the fleet, from when it joins until it ends. Its number, such as #12, identifies it.',
    ],
    'harness' => [
        'term' => 'Harness',
        'means' => 'The program the agent runs in, such as Claude Code or Cursor.',
    ],
    'machine_label' => [
        'term' => 'Machine label',
        'means' => 'The name a machine gave itself when it enrolled. The machine chose it, so treat it as a claim rather than a fact.',
    ],
    'installation' => [
        'term' => 'Installation',
        'means' => 'One machine and harness that a developer approved to join the fleet. Its sessions act on that developer\'s behalf until it is revoked or expires.',
    ],
    'role' => [
        'term' => 'Role',
        'means' => 'What a session does in the fleet: build works on tickets, ci checks and merges pull requests, and coordinator places work and directs other sessions.',
    ],
    'coordinator' => [
        'term' => 'Coordinator',
        'means' => 'A session allowed to place work on any developer\'s lanes and to send instructions to the whole fleet. The coordinator badge marks something such a session wrote.',
    ],
    'lane' => [
        'term' => 'Lane',
        'means' => 'One session that can take work, shown with its developer, machine and working folder. A coordinator places work on lanes.',
    ],
    'task' => [
        'term' => 'Task',
        'means' => 'One piece of work the fleet has been asked to do, usually about one ticket.',
    ],
    'ticket' => [
        'term' => 'Ticket',
        'means' => 'The GitHub issue a task is about, written as owner/repository#number.',
    ],
    'lock' => [
        'term' => 'Lock',
        'means' => 'A name an agent claims so that others leave that thing alone, such as a branch. It is a claim everyone can see rather than one anything enforces, and it runs out on its own.',
    ],
    'queue' => [
        'term' => 'Queue',
        'means' => 'Every task the fleet has been asked to do, most urgent first.',
    ],
    'change_feed' => [
        'term' => 'Change feed',
        'means' => 'The fleet\'s running record: every task change, lock, session change, message and instruction, newest first.',
    ],

    // The overview's counts
    'live_agents' => [
        'term' => 'Live agents',
        'means' => 'Sessions that can still act: active ones, and stale ones that have not yet gone.',
    ],
    'open_tasks' => [
        'term' => 'Open tasks',
        'means' => 'Tasks not yet done, failed or cancelled.',
    ],
    'held_locks' => [
        'term' => 'Held locks',
        'means' => 'Locks that name a holder, including ones whose lease has run out and that nobody has taken since.',
    ],

    // A session's status
    'active' => [
        'term' => 'Active',
        'means' => 'The session was heard from recently and can act.',
    ],
    'stale' => [
        'term' => 'Stale',
        'means' => 'The session has not been heard from for a while. It keeps what it holds, and becomes active again as soon as it is heard from.',
    ],
    'gone' => [
        'term' => 'Gone',
        'means' => 'The session ended, was revoked, or was silent for too long. It can no longer act, and whatever it held was released.',
    ],
    'live_scope' => [
        'term' => 'Live and All',
        'means' => 'Live lists only sessions that can still act. All adds the ones that have gone.',
    ],
    'working_in' => [
        'term' => 'Working in',
        'means' => 'The repository the session said it works in, and the folder on its machine it works from.',
    ],

    // The lane board
    'working' => [
        'term' => 'Working',
        'means' => 'The lane holds at least one task or, for a gate, is checking a pull request.',
    ],
    'idle' => [
        'term' => 'Idle',
        'means' => 'The lane holds no task, and nothing is stopping it from taking one.',
    ],
    'parked' => [
        'term' => 'Parked',
        'means' => 'The lane\'s developer has said this seat takes no new work. It stays parked until they lift it.',
    ],
    'blocked' => [
        'term' => 'Blocked',
        'means' => 'The lane is waiting on someone before it takes work: a developer\'s decision or action, or another ticket. On what names who.',
    ],
    'not_observed' => [
        'term' => 'Not observed',
        'means' => 'The lane\'s session is stale, so what it is doing cannot be read right now.',
    ],
    'gate' => [
        'term' => 'Gate',
        'means' => 'A lane in the ci role. It checks the pull requests other lanes open, then merges each one or hands it back.',
    ],
    'watcher' => [
        'term' => 'Watcher',
        'means' => 'The helper that wakes an idle agent when something arrives for it. Alive means it checked in recently, stale that it has not for a while, absent that it never has, and unknown that its last check-in is too old to judge.',
    ],
    'tickets_held' => [
        'term' => 'Tickets held',
        'means' => 'How many tasks the lane holds now, out of the most its seat allows at once.',
    ],
    'hand_back' => [
        'term' => 'Hand-back',
        'means' => 'A pull request a gate returned to the lane that made it, to fix something before it can merge.',
    ],
    'subagent' => [
        'term' => 'Subagent',
        'means' => 'A helper the lane\'s agent started to work one of its tasks. The label after it is the lane\'s own name for that helper.',
    ],
    'taken_up' => [
        'term' => 'Placed and taken up',
        'means' => 'Placed means the task is on the lane. Taken up means the lane has started it. Placed and told means a coordinator put it there and sent instructions, and chosen by the lane means the lane took it itself.',
    ],
    'validating' => [
        'term' => 'Validating',
        'means' => 'The gate is checking that pull request now. Queued counts the pull requests waiting for the gate after it.',
    ],
    'known_since' => [
        'term' => 'Known since',
        'means' => 'When the fleet last heard from the lane\'s session.',
    ],
    'open_issues' => [
        'term' => 'Open issues',
        'means' => 'How many issues are open in the repository, read from GitHub every few minutes, and how that compares with 08:00 today. Count unreadable means the last reading is too old to trust.',
    ],
    'waiting_on_developer' => [
        'term' => 'Waiting on a developer',
        'means' => 'Questions and actions an agent cannot settle itself, recorded for the developer named. General items are for anyone.',
    ],

    // Locks
    'held_by_lock' => [
        'term' => 'Held by',
        'means' => 'The session holding the lock now. After names the one that held it before.',
    ],
    'fence' => [
        'term' => 'Fence',
        'means' => 'A number that goes up every time any lock is taken. A lock\'s newest holder always has the larger number, which tells it apart from an earlier holder that has not noticed it lost the lock.',
    ],
    'lease' => [
        'term' => 'Lease',
        'means' => 'How long the lock lasts unless its holder renews it. A lease that has run out is highlighted: anyone may take that lock.',
    ],
    'held_scope' => [
        'term' => 'Held and All',
        'means' => 'Held lists only locks with a holder. All adds the ones nobody holds.',
    ],

    // The queue
    'pending' => [
        'term' => 'pending',
        'means' => 'The task is waiting for a lane to take it.',
    ],
    'claimed' => [
        'term' => 'claimed',
        'means' => 'A lane holds the task but has not started it.',
    ],
    'in_progress' => [
        'term' => 'in_progress',
        'means' => 'A lane is working on the task.',
    ],
    'blocked_task' => [
        'term' => 'blocked',
        'means' => 'A lane holds the task but is waiting on something before it can go on.',
    ],
    'done' => [
        'term' => 'done',
        'means' => 'The task is finished.',
    ],
    'failed' => [
        'term' => 'failed',
        'means' => 'The lane stopped without finishing the task. Its result says why.',
    ],
    'cancelled' => [
        'term' => 'cancelled',
        'means' => 'The task was withdrawn before it was finished.',
    ],
    'priority' => [
        'term' => 'Priority',
        'means' => 'How urgent the task is, from 0 to 9. Higher numbers come first.',
    ],
    'held_by_task' => [
        'term' => 'Held by',
        'means' => 'The developer whose lane holds the task now. Nobody means it is waiting for a lane.',
    ],

    // The change feed
    'entry_type' => [
        'term' => 'Entry type',
        'means' => 'The label on each entry, such as task.claimed. The part before the dot says what changed: a session, a task, a lock, a lane, a placement or an installation.',
    ],
    'narration' => [
        'term' => 'narration',
        'means' => 'An agent saying what it is doing. It reaches its own developer\'s sessions, and everyone when a coordinator writes it.',
    ],
    'directive' => [
        'term' => 'directive',
        'means' => 'An instruction a coordinator sent to the whole fleet.',
    ],
    'placement_instruction' => [
        'term' => 'placement.instruction',
        'means' => 'The instructions a coordinator sent with a task it placed on a lane.',
    ],

    // Administration
    'usable' => [
        'term' => 'Usable and All',
        'means' => 'Usable lists installations that are neither revoked nor expired. All adds those that are.',
    ],
    'revoked' => [
        'term' => 'Revoked',
        'means' => 'An administrator stopped the installation. Its credential and every session token it issued no longer work.',
    ],
    'expired' => [
        'term' => 'Expired',
        'means' => 'The installation reached its end date. Its machine has to enroll again to rejoin.',
    ],
    'ephemeral' => [
        'term' => 'Ephemeral',
        'means' => 'A session that asked not to be announced: the fleet records no joining or leaving for it, and other agents are not shown it.',
    ],
    'asked_for_role' => [
        'term' => 'Asked for',
        'means' => 'The role the session asked to be given. Approve grants it, and Deny leaves the session in the role it has.',
    ],
    'make_role' => [
        'term' => 'Make, then a role',
        'means' => 'Puts the session in that role straight away, with no request needed. It is how a session is changed or demoted.',
    ],
    'revoke_session' => [
        'term' => 'Revoke session',
        'means' => 'Ends that one session now. Its installation, and any other sessions it has, keep working.',
    ],
    'revoke_installation' => [
        'term' => 'Revoke installation',
        'means' => 'Stops the machine for good: its credential and every session token it issued stop working at once. It has to enroll again to rejoin.',
    ],

    // Seats and hours
    'seat' => [
        'term' => 'Seat',
        'means' => 'One of your machines working in one repository and folder. What you set here applies to every session that works there.',
    ],
    'park' => [
        'term' => 'Park and Lift',
        'means' => 'Park stops new work being placed on the seat, and Lift allows it again. Only you can lift a seat you parked; it never lifts on its own.',
    ],
    'exempt' => [
        'term' => 'Exempt from hours',
        'means' => 'The seat takes new work at any time, whatever your assignment hours say. Apply my hours undoes it.',
    ],
    'tickets_at_once' => [
        'term' => 'Tickets at once',
        'means' => 'The most tasks one session in the seat may hold at the same time.',
    ],
    'placement' => [
        'term' => 'Placement',
        'means' => 'A coordinator putting a task on a lane.',
    ],
    'waive' => [
        'term' => 'Waive a placement refusal',
        'means' => 'A placement is refused when one of the listed rules is broken, for example outside your hours. Waiving a rule lets the next placement on the seat through once, and withdrawing the waiver takes it back before it is used.',
    ],
    'assignment_hours' => [
        'term' => 'Assignment hours',
        'means' => 'The hours, on your own clock, when your seats take new work. Work already placed, gate checks and hand-backs are never held to them.',
    ],
    'days_off' => [
        'term' => 'Days off',
        'means' => 'Dates on your clock when your seats take no new work. They apply once you have set assignment hours.',
    ],

    // Enrollment
    'enrollment' => [
        'term' => 'Enrollment',
        'means' => 'Connecting a new machine to the fleet. The machine shows a short code, and a developer approves it on this page.',
    ],
    'enrollment_code' => [
        'term' => 'Code',
        'means' => 'The short code shown on the machine asking to enroll. It expires a few minutes after the machine asks for it.',
    ],
    'abilities' => [
        'term' => 'Asked for',
        'means' => 'The permissions the machine requested, as it wrote them. Approving grants the standard set described below the list, whatever the list says.',
    ],
];
