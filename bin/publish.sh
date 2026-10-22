#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

repo="RM-FM/azuracast-sweepers"
identity="RM-FM <40538914+RM-FM@users.noreply.github.com>"
git rev-parse --verify main >/dev/null

[[ "$(gh api user --jq .login)" == "RM-FM" ]] || {
    echo "Authenticate GitHub CLI as RM-FM before publishing." >&2
    exit 1
}
[[ -z "$(git status --porcelain)" ]] || {
    echo "Commit or preserve local changes before publishing." >&2
    exit 1
}
while IFS= read -r line; do
    [[ "$line" == "$identity|$identity" ]] || {
        echo "History contains an author or committer other than RM-FM." >&2
        exit 1
    }
done < <(git log --format='%an <%ae>|%cn <%ce>')

if gh repo view "$repo" --json visibility >/dev/null 2>&1; then
    [[ "$(gh repo view "$repo" --json visibility --jq .visibility)" == "PUBLIC" ]] || {
        echo "The existing repository is not public; no visibility was changed." >&2
        exit 1
    }
    if git remote get-url origin >/dev/null 2>&1; then
        [[ "$(git remote get-url origin)" == "https://github.com/$repo.git" ]] || {
            echo "Unexpected origin URL; no remote was changed." >&2
            exit 1
        }
    else
        git remote add origin "https://github.com/$repo.git"
    fi
    git push --set-upstream origin main
else
    gh repo create "$repo" --public --source . --remote origin --push \
        --description "Intro-aware jingle overlays and a standalone intro editor for AzuraCast."
fi

gh repo view "$repo" --json url,visibility --jq '.url + " (" + .visibility + ")"'
