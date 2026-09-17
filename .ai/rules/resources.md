---
paths:
  - 'app/Modules/*/Http/Resources/**'
---

# Resources

## Always ->resolve($request) a nested resource collection
A nested `XResource::collection(...)` left inside a resource's `toArray()` reaches the page as `{ "data": [...] }`, not a list. Inertia's `resolvePropertyInstances` walks the props and calls `toResponse()` on any JsonResource it finds — even one nested inside the array a controller produced with `->resolve($request)` — and `toResponse()` applies the `$wrap` = "data" envelope.

So the prop the Vue page receives is an object, `v-for` walks the wrapper, and any child component that indexes into an item (`message.attachments.filter(...)`) throws and blanks the whole screen. Nothing 500s server-side, so the log is silent; the evidence is in `browser-logs`.

Always resolve nested collections:

    'messages' => $this->whenLoaded(
        'messages',
        fn (): array => MessageResource::collection($this->messages)->resolve($request),
    ),

`whenLoaded` with the closure — not `Resource::collection($this->whenLoaded('x'))->resolve(...)` — so an unloaded relation stays a MissingValue and the key is omitted, rather than being sent as an empty list the page cannot tell from "no rows".

Covered by "sends the thread pages a plain list of messages" (ThreadTest) and "sends the order pages a plain list of items" (OrderPagesTest). Both need TWO rows: a wrapped collection still has a length of one, so a one-row fixture passes either way.
