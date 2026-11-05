# Rule — interface design for the dashboard: structure, words, type, motion

The dashboard is a dense operator tool inside an established daisyUI system, and most UI tickets here correct something in it. This rule is the short list of habits that keep those corrections from adding noise. Apply it in the same change as the UI work, alongside [`accessibility`](accessibility.md), which wins wherever the two disagree.

**Where this came from.** It harvests the parts of Anthropic's `frontend-design` plugin ([`anthropics/claude-plugins-official`](https://github.com/anthropics/claude-plugins-official), under `plugins/frontend-design`) that fit a corrective, accessibility-first dashboard, as decided on #495. The plugin was not installed, because it loads on any frontend work and pushes a distinctive visual identity. The text below is this repository's own wording; the source is all-rights-reserved, so nothing is quoted from it, and it was not re-read when this was written (the candidate list on #495 is what was applied). What the plugin pushes for a new product, a bold palette, display typefaces, hero sections and "take aesthetic risk", is out of scope here. A deliberate visual redesign is its own `decision-fork` ticket under [`design-decision-forks`](design-decision-forks.md).

**A ticket's recorded `## Decision` stands.** Where this rule would disagree with one, the decision wins and the rule is the one that bends. Two are named below because they look like exceptions.

## Structure is information

- **A border, divider, number or label says something about the content, so use one only when it does.** Group related items and separate unrelated ones; a rule between every row of a table that already has rows says nothing.
- **Number only a real sequence**, such as steps that must happen in order. A numbered marker on items that have no order tells the reader there is one.
- **A visible boundary on a control is structure too.** An action that is not selected still has to look like a control, which is what #309 and #482 settled; this rule agrees with them and adds nothing to them.

## Words on the page

- **Name things by what the person manages, not by how the system is built.** "Seat" and "lane" are the dashboard's own terms and are glossed in place per the accessibility rule; a table name, a class name or a queue name is not a label.
- **Active voice, plain words, sentence case, no filler.** Lead with the keyword, as the accessibility rule's plain-language bullet asks.
- **A button says exactly what it does**, such as "Save changes", never "Submit". **An action keeps one name through its flow**: the button that publishes says "Publish" and its result says "Published". #482's decision is this rule applied: the state reads "Hours: ignored" and the button reads "Apply my hours", so the state and the action never read alike.
- **An error says what happened and what to do next, without apologizing.** #452's refusals are the model: "Expired: this code is no longer waiting. Ask the machine for a new one." It sits beside the thing it is about, per the accessibility rule's feedback bullet.
- **An empty state invites the next action** rather than only saying nothing is there.

## Typographic tells to avoid

- **All-caps labels.** They are harder to read, and for a reader on a screen reader or with dyslexia they cost more than they give. The one existing use is the sidebar's `Menu` heading, which stays as it is; do not add another.
- **A label above every block of content.** A heading earns its place by naming a section the reader would otherwise have to infer. A label that repeats the first line under it is clutter.
- **One accented word in a heading**, a different weight or color on a single word for effect. It carries no meaning and breaks under forced colors.
- A state line such as `Hours: ignored`, or a prefix such as `Expired:`, is **not** one of these. Each is a labelled state that #482 and #452 decided on, and the label is the information.

## Motion

- **Move only to answer a person's action, or to draw attention once.** A decorative entrance, a hover flourish or a looping animation does neither.
- Everything that does move stops under `prefers-reduced-motion`, per the accessibility rule, which owns that requirement. A refresh by `wire:poll` must not animate the rows it replaces.

## Line length

Prose is kept to a `rem` measure, not a character count, by the accessibility rule's readable-text bullet (`max-w-xl`, which is 36rem). That is the dashboard's line-length rule, and it is already shorter than the roughly 80 characters other guidance suggests, so nothing is added here.

## CSS specificity

Two selectors that set the same property for the same element, such as a type-based one and an element-based one both setting spacing, silently cancel each other and leave the result to source order. Before adding a utility to fix spacing or color, check that nothing else already sets it. The dashboard stylesheet is a committed build artifact (see `CLAUDE.md`), so a conflict is shipped as built.

## While building

- **Look at it.** Open the page, in both themes and at 390px wide, and judge what is there rather than what the markup says. The browser job in CI catches contrast and names, not clutter.
- **Remove one accessory.** Before finishing, find one piece of decoration that serves no purpose, a border, an icon, a badge or a line of copy, and cut it. If nothing qualifies, say so in the pull request.

## The DRY line

This file owns **interface structure, wording, type and motion for the dashboard**. Contrast, target size, focus, zoom and reduced motion are [`accessibility`](accessibility.md). A genuine choice between two designs is [`design-decision-forks`](design-decision-forks.md). How a pull request describes a UI change is [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md).
